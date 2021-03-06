<?php

namespace Directus\Application;

use Cache\Adapter\Apc\ApcCachePool;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Adapter\Common\PhpCachePool;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use Cache\Adapter\Memcached\MemcachedCachePool;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Cache\Adapter\Redis\RedisCachePool;
use Cache\Adapter\Void\VoidCachePool;
use Directus\Application\ErrorHandlers\ErrorHandler;
use function Directus\array_get;
use Directus\Authentication\Provider;
use Directus\Authentication\Sso\Social;
use Directus\Authentication\User\Provider\UserTableGatewayProvider;
use Directus\Cache\Response;
use Directus\Config\StatusMapping;
use Directus\Database\Connection;
use Directus\Database\Exception\ConnectionFailedException;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\SchemaFactory;
use Directus\Database\Schema\SchemaManager;
use Directus\Database\TableGateway\BaseTableGateway;
use Directus\Database\TableGateway\DirectusPermissionsTableGateway;
use Directus\Database\TableGateway\DirectusSettingsTableGateway;
use Directus\Database\TableGateway\DirectusUsersTableGateway;
use Directus\Database\TableGateway\RelationalTableGateway;
use Directus\Database\SchemaService;
use Directus\Embed\EmbedManager;
use Directus\Exception\ForbiddenException;
use Directus\Exception\RuntimeException;
use Directus\Filesystem\Files;
use Directus\Filesystem\Filesystem;
use Directus\Filesystem\FilesystemFactory;
use function Directus\generate_uui4;
use function Directus\get_api_env_from_request;
use Directus\Hash\HashManager;
use Directus\Hook\Emitter;
use Directus\Hook\Payload;
use Directus\Mail\Mailer;
use Directus\Mail\TransportManager;
use Directus\Mail\Transports\SimpleFileTransport;
use Directus\Permissions\Acl;
use Directus\Services\AuthService;
use Directus\Session\Session;
use Directus\Session\Storage\NativeSessionStorage;
use Directus\Util\ArrayUtils;
use Directus\Util\DateTimeUtils;
use Directus\Util\StringUtils;
use League\Flysystem\Adapter\Local;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Views\Twig;
use Zend\Db\TableGateway\TableGateway;

class CoreServicesProvider
{
    public function register($container)
    {
        $container['database']          = $this->getDatabase();
        $container['logger']            = $this->getLogger();
        $container['hook_emitter']      = $this->getEmitter();
        $container['auth']              = $this->getAuth();
        $container['external_auth']     = $this->getExternalAuth();
        $container['session']           = $this->getSession();
        $container['acl']               = $this->getAcl();
        $container['errorHandler']      = $this->getErrorHandler();
        $container['phpErrorHandler']   = $this->getErrorHandler();
        $container['schema_adapter']    = $this->getSchemaAdapter();
        $container['schema_manager']    = $this->getSchemaManager();
        $container['schema_factory']    = $this->getSchemaFactory();
        $container['hash_manager']      = $this->getHashManager();
        $container['embed_manager']     = $this->getEmbedManager();
        $container['filesystem']        = $this->getFileSystem();
        $container['filesystem_thumb']  = $this->getThumbFilesystem();
        $container['files']             = $this->getFiles();
        $container['mailer_transport']  = $this->getMailerTransportManager();
        $container['mailer']            = $this->getMailer();
        $container['mail_view']         = $this->getMailView();
        $container['app_settings']      = $this->getSettings();
        $container['status_mapping']    = $this->getStatusMapping();

        // Move this separately to avoid clogging one class
        $container['cache']             = $this->getCache();
        $container['response_cache']    = $this->getResponseCache();

        $container['services']          = $this->getServices($container);
    }

    /**
     * @return \Closure
     */
    protected function getLogger()
    {
        /**
         * @param Container $container
         * @return Logger
         */
        $logger = function ($container) {
            $logger = new Logger(sprintf('api[%s]', get_api_env_from_request()));
            $formatter = new LineFormatter();
            $formatter->allowInlineLineBreaks();
            $formatter->includeStacktraces();
            // TODO: Move log configuration outside "slim app" settings
            $path = $container->get('path_base') . '/logs';
            $config = $container->get('config');
            if ($config->has('settings.logger.path')) {
                $path = $config->get('settings.logger.path');
            }

            $filenameFormat = '%s.%s.log';
            foreach (Logger::getLevels() as $name => $level) {
                $handler = new StreamHandler(
                    $path . '/' . sprintf($filenameFormat, strtolower($name), date('Y-m-d')),
                    $level,
                    false
                );

                $handler->setFormatter($formatter);
                $logger->pushHandler($handler);
            }

            return $logger;
        };

        return $logger;
    }

    /**
     * @return \Closure
     */
    protected function getErrorHandler()
    {
        /**
         * @param Container $container
         *
         * @return ErrorHandler
         */
        $errorHandler = function (Container $container) {
            $hookEmitter = $container['hook_emitter'];

            return new ErrorHandler($hookEmitter, [
                'env' => $container->get('config')->get('app.env', 'development')
            ]);
        };

        return $errorHandler;
    }

    /**
     * @return \Closure
     */
    protected function getEmitter()
    {
        return function (Container $container) {
            $emitter = new Emitter();
            $cachePool = $container->get('cache');

            // TODO: Move this separately, this is temporary while we move things around
            $emitter->addFilter('load.relational.onetomany', function (Payload $payload) {
                $rows = $payload->getData();
                /** @var Field $column */
                $column = $payload->attribute('column');

                if ($column->getInterface() !== 'translation') {
                    return $payload;
                }

                $options = $column->getOptions();
                $code = ArrayUtils::get($options, 'languages_code_column', 'id');
                $languagesTable = ArrayUtils::get($options, 'languages_table');
                $languageIdColumn = ArrayUtils::get($options, 'left_column_name');

                if (!$languagesTable) {
                    throw new \Exception('Translations language table not defined for ' . $languageIdColumn);
                }

                $tableSchema = SchemaService::getCollection($languagesTable);
                $primaryKeyColumn = 'id';
                foreach($tableSchema->getColumns() as $column) {
                    if ($column->isPrimary()) {
                        $primaryKeyColumn = $column->getName();
                        break;
                    }
                }

                $newData = [];
                foreach($rows as $row) {
                    $index = $row[$languageIdColumn];
                    if (is_array($row[$languageIdColumn])) {
                        $index = $row[$languageIdColumn][$code];
                        $row[$languageIdColumn] = $row[$languageIdColumn][$primaryKeyColumn];
                    }

                    $newData[$index] = $row;
                }

                $payload->replace($newData);

                return $payload;
            }, $emitter::P_HIGH);

            // Cache subscriptions
            $emitter->addAction('postUpdate', function (RelationalTableGateway $gateway, $data) use ($cachePool) {
                if(isset($data[$gateway->primaryKeyFieldName])) {
                    $cachePool->invalidateTags(['entity_'.$gateway->getTable().'_'.$data[$gateway->primaryKeyFieldName]]);
                }
            });

            $cacheTableTagInvalidator = function ($tableName) use ($cachePool) {
                $cachePool->invalidateTags(['table_'.$tableName]);
            };

            foreach (['collection.update:after', 'collection.drop:after'] as $action) {
                $emitter->addAction($action, $cacheTableTagInvalidator);
            }

            $emitter->addAction('collection.delete:after', function ($tableName, $ids) use ($cachePool){
                foreach ($ids as $id) {
                    $cachePool->invalidateTags(['entity_'.$tableName.'_'.$id]);
                }
            });

            $emitter->addAction('collection.update.directus_permissions:after', function ($data) use($container, $cachePool) {
                $acl = $container->get('acl');
                $dbConnection = $container->get('database');
                $privileges = new DirectusPermissionsTableGateway($dbConnection, $acl);
                $record = $privileges->fetchById($data['id']);
                $cachePool->invalidateTags(['permissions_collection_'.$record['collection']]);
            });
            // /Cache subscriptions

            $emitter->addAction('application.error', function ($e) use($container) {
                /** @var Logger $logger */
                $logger = $container->get('logger');

                $logger->error($e);
            });
            $emitter->addFilter('response', function (Payload $payload) use ($container) {
                /** @var Acl $acl */
                $acl = $container->get('acl');
                if ($acl->isPublic() || !$acl->getUserId()) {
                    $payload->set('public', true);
                }
                return $payload;
            });
            $emitter->addAction('collection.insert.directus_roles', function ($data) use ($container) {
                $acl = $container->get('acl');
                $zendDb = $container->get('database');
                $privilegesTable = new DirectusPermissionsTableGateway($zendDb, $acl);

                $privilegesTable->insertPrivilege([
                    'role' => $data['id'],
                    'collection' => 'directus_users',
                    'create' => Acl::LEVEL_NONE,
                    'read' => Acl::LEVEL_FULL,
                    'update' => Acl::LEVEL_MINE,
                    'delete' => Acl::LEVEL_NONE,
                    'read_field_blacklist' => 'token',
                    'write_field_blacklist' => 'token'
                ]);
            });
            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($container) {
                $collectionName = $payload->attribute('collection_name');
                $collection = SchemaService::getCollection($collectionName);
                /** @var Acl $acl */
                $acl = $container->get('acl');


                if ($dateCreated = $collection->getDateCreatedField()) {
                    $payload[$dateCreated] = DateTimeUtils::nowInUTC()->toString();
                }

                if ($dateCreated = $collection->getDateModifiedField()) {
                    $payload[$dateCreated] = DateTimeUtils::nowInUTC()->toString();
                }

                // Directus Users created user are themselves (primary key)
                // populating that field will be a duplicated primary key violation
                if ($collection->getName() === 'directus_users') {
                    return $payload;
                }

                $userCreated = $collection->getUserCreatedField();
                $userModified = $collection->getUserModifiedField();

                if ($userCreated) {
                    $payload[$userCreated->getName()] = $acl->getUserId();
                }

                if ($userModified) {
                    $payload[$userModified->getName()] = $acl->getUserId();
                }

                return $payload;
            }, Emitter::P_HIGH);
            $savesFile = function (Payload $payload, $replace = false) use ($container) {
                $collectionName = $payload->attribute('collection_name');
                if ($collectionName !== SchemaManager::COLLECTION_FILES) {
                    return null;
                }

                if ($replace === true && !$payload->has('data')) {
                    return null;
                }

                /** @var Acl $auth */
                $acl = $container->get('acl');
                $data = $payload->getData();

                /** @var \Directus\Filesystem\Files $files */
                $files = $container->get('files');

                if (array_key_exists('data', $data) && filter_var($data['data'], FILTER_VALIDATE_URL)) {
                    $dataInfo = $files->getLink($data['data']);
                } else {
                    $dataInfo = $files->getDataInfo($data['data']);
                }

                $type = ArrayUtils::get($dataInfo, 'type', ArrayUtils::get($data, 'type'));

                if (strpos($type, 'embed/') === 0) {
                    $recordData = $files->saveEmbedData($dataInfo);
                } else {
                    $recordData = $files->saveData($payload['data'], $payload['filename'], $replace);
                }

                $payload->replace($recordData);
                $payload->remove('data');
                $payload->remove('html');
                if (!$replace) {
                    $payload->set('upload_user', $acl->getUserId());
                    $payload->set('upload_date', DateTimeUtils::nowInUTC()->toString());
                }
            };
            $emitter->addFilter('collection.update:before', function (Payload $payload) use ($container, $savesFile) {
                $collection = SchemaService::getCollection($payload->attribute('collection_name'));

                /** @var Acl $acl */
                $acl = $container->get('acl');
                if ($dateModified = $collection->getDateModifiedField()) {
                    $payload[$dateModified] = DateTimeUtils::nowInUTC()->toString();
                }

                if ($userModified = $collection->getUserModifiedField()) {
                    $payload[$userModified] = $acl->getUserId();
                }

                // NOTE: exclude date_uploaded from updating a file record
                if ($collection->getName() === 'directus_files') {
                    $payload->remove('date_uploaded');
                }

                $savesFile($payload, true);

                return $payload;
            }, Emitter::P_HIGH);
            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($savesFile) {
                $savesFile($payload, false);

                return $payload;
            });
            $addFilesUrl = function ($rows) {
                return \Directus\append_storage_information($rows);
            };
            $emitter->addFilter('collection.select.directus_files:before', function (Payload $payload) {
                $columns = $payload->get('columns');
                if (!in_array('filename', $columns)) {
                    $columns[] = 'filename';
                    $payload->set('columns', $columns);
                }
                return $payload;
            });

            // -- Data types -----------------------------------------------------------------------------
            // TODO: improve Parse boolean/json/array almost similar code
            $parseArray = function ($decode, $collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isArray()) {
                        continue;
                    }

                    $key = $field->getName();
                    $value = $data[$key];

                    // convert string to array
                    $decodeFn = function ($value) {
                        // if empty string, empty array, null or false
                        if (empty($value) && !is_numeric($value)) {
                            $value = [];
                        } else {
                            $value = !is_array($value) ? explode(',', $value) :  $value;
                        }

                        return $value;
                    };

                    // convert array into string
                    $encodeFn = function ($value) {
                        return is_array($value) ? implode(',', $value) : $value;
                    };

                    // NOTE: If the array has value with comma it will be treat as a separate value
                    // should we encode the commas to "hide" the comma when splitting the values?
                    if ($decode) {
                        $value = $decodeFn($value);
                    } else {
                        $value = $encodeFn($value);
                    }

                    $data[$key] = $value;
                }

                return $data;
            };

            $parseBoolean = function ($collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isBoolean()) {
                        continue;
                    }

                    $key = $field->getName();
                    $data[$key] = boolval($data[$key]);
                }

                return $data;
            };
            $parseJson = function ($decode, $collection, $data) use ($container) {
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collectionObject = $schemaManager->getCollection($collection);

                foreach ($collectionObject->getFields(array_keys($data)) as $field) {
                    if (!$field->isJson()) {
                        continue;
                    }

                    $key = $field->getName();
                    $value = $data[$key];

                    if ($decode === true) {
                        $value = is_string($value) ? json_decode($value) : $value;
                    } else {
                        $value = !is_string($value) ? json_encode($value) : $value;
                    }

                    $data[$key] = $value;
                }

                return $data;
            };

            $emitter->addFilter('collection.insert:before', function (Payload $payload) use ($parseJson, $parseArray) {
                $payload->replace(
                    $parseJson(
                        false,
                        $payload->attribute('collection_name'),
                        $payload->getData()
                    )
                );

                $payload->replace($parseArray(false, $payload->attribute('collection_name'), $payload->getData()));

                return $payload;
            });
            $emitter->addFilter('collection.update:before', function (Payload $payload) use ($parseJson, $parseArray) {
                $payload->replace(
                    $parseJson(
                        false,
                        $payload->attribute('collection_name'),
                        $payload->getData()
                    )
                );
                $payload->replace(
                    $parseArray(
                        false,
                        $payload->attribute('collection_name'),
                        $payload->getData()
                    )
                );

                return $payload;
            });
            $emitter->addFilter('collection.select', function (Payload $payload) use ($container, $parseJson, $parseArray, $parseBoolean) {
                $rows = $payload->getData();
                $collectionName = $payload->attribute('collection_name');
                /** @var SchemaManager $schemaManager */
                $schemaManager = $container->get('schema_manager');
                $collection = $schemaManager->getCollection($collectionName);

                $hasJsonField = $collection->hasJsonField();
                $hasBooleanField = $collection->hasBooleanField();
                $hasArrayField = $collection->hasArrayField();

                if (!$hasArrayField && !$hasBooleanField && !$hasJsonField) {
                    return $payload;
                }

                foreach ($rows as $key => $row) {
                    if ($hasJsonField) {
                        $row = $parseJson(true, $collectionName, $row);
                    }

                    if ($hasBooleanField) {
                        $row = $parseBoolean($collectionName, $row);
                    }

                    if ($hasArrayField) {
                        $row = $parseArray(true, $collectionName, $row);
                    }

                    $rows[$key] = $row;
                }

                $payload->replace($rows);

                return $payload;
            });
            // -------------------------------------------------------------------------------------------
            // Add file url and thumb url
            $emitter->addFilter('collection.select.directus_files', function (Payload $payload) use ($addFilesUrl, $container) {
                $rows = $addFilesUrl($payload->getData());

                $payload->replace($rows);

                return $payload;
            });
            $emitter->addFilter('collection.select.directus_users', function (Payload $payload) use ($container) {
                $acl = $container->get('acl');
                $rows = $payload->getData();
                $userId = $acl->getUserId();
                foreach ($rows as &$row) {
                    $omit = [
                        'password'
                    ];
                    // Authenticated user can see their private info
                    // Admin can see all users private info
                    if (!$acl->isAdmin() && $userId !== $row['id']) {
                        $omit = array_merge($omit, [
                            'token',
                            'email_notifications',
                            'last_access',
                            'last_page'
                        ]);
                    }
                    $row = ArrayUtils::omit($row, $omit);
                }
                $payload->replace($rows);
                return $payload;
            });
            $hashUserPassword = function (Payload $payload) use ($container) {
                if ($payload->has('password')) {
                    $auth = $container->get('auth');
                    $payload['password'] = $auth->hashPassword($payload['password']);
                }
                return $payload;
            };
            // TODO: Merge with hash user password
            $onInsertOrUpdate = function (Payload $payload) use ($container) {
                /** @var Provider $auth */
                $auth = $container->get('auth');
                $collectionName = $payload->attribute('collection_name');

                if (SchemaService::isSystemCollection($collectionName)) {
                    return $payload;
                }

                $collection = SchemaService::getCollection($collectionName);
                $data = $payload->getData();
                foreach ($data as $key => $value) {
                    $column = $collection->getField($key);
                    if (!$column) {
                        continue;
                    }

                    if ($column->getInterface() === 'password') {
                        // TODO: Use custom password hashing method
                        $payload->set($key, $auth->hashPassword($value));
                    }
                }

                return $payload;
            };
            $preventNonAdminFromUpdateRoles = function (array $payload) use ($container) {
                /** @var Acl $acl */
                $acl = $container->get('acl');

                if (!$acl->isAdmin()) {
                    throw new ForbiddenException('You are not allowed to create, update or delete roles');
                }
            };

            $emitter->addAction('collection.insert.directus_user_roles:before', $preventNonAdminFromUpdateRoles);
            $emitter->addAction('collection.update.directus_user_roles:before', $preventNonAdminFromUpdateRoles);
            $emitter->addAction('collection.delete.directus_user_roles:before', $preventNonAdminFromUpdateRoles);
            $generateExternalId = function (Payload $payload) {
                // generate an external id if none is passed
                if (!$payload->get('external_id')) {
                    $payload->set('external_id', generate_uui4());
                }

                return $payload;
            };
            $emitter->addFilter('collection.insert.directus_users:before', $hashUserPassword);
            $emitter->addFilter('collection.update.directus_users:before', $hashUserPassword);
            $emitter->addFilter('collection.insert.directus_users:before', $generateExternalId);
            $emitter->addFilter('collection.insert.directus_roles:before', $generateExternalId);
            // Hash value to any non system table password interface column
            $emitter->addFilter('collection.insert:before', $onInsertOrUpdate);
            $emitter->addFilter('collection.update:before', $onInsertOrUpdate);
            $preventUsePublicGroup = function (Payload $payload) use ($container) {
                $data = $payload->getData();
                if (!ArrayUtils::has($data, 'role')) {
                    return $payload;
                }

                $roleId = ArrayUtils::get($data, 'role');
                if (is_array($roleId)) {
                    $roleId = ArrayUtils::get($roleId, 'id');
                }

                if (!$roleId) {
                    return $payload;
                }

                $zendDb = $container->get('database');
                $acl = $container->get('acl');
                $tableGateway = new BaseTableGateway(SchemaManager::COLLECTION_ROLES, $zendDb, $acl);
                $row = $tableGateway->select(['id' => $roleId])->current();
                if (strtolower($row->name) === 'public') {
                    throw new ForbiddenException('Users cannot be added into the public group');
                }

                return $payload;
            };
            $emitter->addFilter('collection.insert.directus_user_roles:before', $preventUsePublicGroup);
            $emitter->addFilter('collection.update.directus_user_roles:before', $preventUsePublicGroup);
            $beforeSavingFiles = function ($payload) use ($container) {
                $acl = $container->get('acl');
                if (!$acl->canUpdate('directus_files')) {
                    throw new ForbiddenException('You are not allowed to upload, edit or delete files');
                }

                return $payload;
            };
            $emitter->addAction('files.saving', $beforeSavingFiles);
            $emitter->addAction('files.thumbnail.saving', $beforeSavingFiles);
            // TODO: Make insert actions and filters
            $emitter->addFilter('collection.insert.directus_files:before', $beforeSavingFiles);
            $emitter->addFilter('collection.update.directus_files:before', $beforeSavingFiles);
            $emitter->addFilter('collection.delete.directus_files:before', $beforeSavingFiles);

            return $emitter;
        };
    }

    /**
     * @return \Closure
     */
    protected function getDatabase()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $dbConfig = $config->get('database');

            // TODO: enforce/check required params

            $charset = ArrayUtils::get($dbConfig, 'charset', 'utf8mb4');

            $dbConfig = [
                'driver' => 'Pdo_' . $dbConfig['type'],
                'host' => $dbConfig['host'],
                'port' => $dbConfig['port'],
                'database' => $dbConfig['name'],
                'username' => $dbConfig['username'],
                'password' => $dbConfig['password'],
                'charset' => $charset,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                \PDO::MYSQL_ATTR_INIT_COMMAND => sprintf('SET NAMES "%s"', $charset)
            ];

            try {
                $db = new Connection($dbConfig);
                $db->connect();
            } catch (\Exception $e) {
                throw new ConnectionFailedException($e);
            }

            return $db;
        };
    }

    /**
     * @return \Closure
     */
    protected function getAuth()
    {
        return function (Container $container) {
            $db = $container->get('database');

            return new Provider(
                new UserTableGatewayProvider(
                    new DirectusUsersTableGateway($db)
                ),
                [
                    'secret_key' => $container->get('config')->get('auth.secret_key')
                ]
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getExternalAuth()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $providersConfig = $config->get('auth.social_providers', []);

            $socialAuth = new Social();

            $coreSso = \Directus\get_custom_x('auth', 'public/extensions/core/auth', true);
            $customSso = \Directus\get_custom_x('auth', 'public/extensions/custom/auth', true);

            // Flag the customs providers in order to choose the correct path for the icons
            $customSso = array_map(function ($config) {
                $config['custom'] = true;

                return $config;
            }, $customSso);

            $ssoProviders = array_merge($coreSso, $customSso);
            foreach ($providersConfig as $providerName => $providerConfig) {
                if (!is_array($providerConfig)) {
                    continue;
                }

                if (ArrayUtils::get($providerConfig, 'enabled') === false) {
                    continue;
                }

                if (array_key_exists($providerName, $ssoProviders) && isset($ssoProviders[$providerName]['provider'])) {
                    $providerInfo = $ssoProviders[$providerName];
                    $class = array_get($providerInfo, 'provider');
                    $custom = array_get($providerInfo, 'custom');

                    if (!class_exists($class)) {
                        throw new RuntimeException(sprintf('Class %s not found', $class));
                    }

                    $socialAuth->register($providerName, new $class($container, array_merge([
                        'custom' => $custom,
                        'callback_url' => \Directus\get_url('/_/auth/sso/' . $providerName . '/callback')
                    ], $providerConfig)));
                }
            }

            return $socialAuth;
        };
    }

    /**
     * @return \Closure
     */
    protected function getSession()
    {
        return function (Container $container) {
            $config = $container->get('config');

            $session = new Session(new NativeSessionStorage($config->get('session', [])));
            $session->getStorage()->start();

            return $session;
        };
    }

    /**
     * @return \Closure
     */
    protected function getAcl()
    {
        return function (Container $container) {
            return new Acl();
        };
    }

    /**
     * @return \Closure
     */
    protected function getCache()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $poolConfig = $config->get('cache.pool');

            if (!$poolConfig || (!is_object($poolConfig) && empty($poolConfig['adapter']))) {
                $poolConfig = ['adapter' => 'void'];
            }

            if (is_object($poolConfig) && $poolConfig instanceof PhpCachePool) {
                $pool = $poolConfig;
            } else {
                if (!in_array($poolConfig['adapter'], ['apc', 'apcu', 'array', 'filesystem', 'memcached', 'redis', 'void'])) {
                    throw new \Exception("Valid cache adapters are 'apc', 'apcu', 'filesystem', 'memcached', 'redis'");
                }

                $pool = new VoidCachePool();

                $adapter = $poolConfig['adapter'];

                if ($adapter == 'apc') {
                    $pool = new ApcCachePool();
                }

                if ($adapter == 'apcu') {
                    $pool = new ApcuCachePool();
                }

                if ($adapter == 'array') {
                    $pool = new ArrayCachePool();
                }

                if ($adapter == 'filesystem') {
                    if (empty($poolConfig['path']) || !is_string($poolConfig['path'])) {
                        throw new \Exception('"cache.pool.path parameter is required for "filesystem" adapter and must be a string');
                    }

                    $cachePath = $poolConfig['path'];
                    if ($cachePath[0] !== '/') {
                        $basePath = $container->get('path_base');
                        $cachePath = rtrim($basePath, '/') . '/' . $cachePath;
                    }

                    $filesystemAdapter = new Local($cachePath);
                    $filesystem = new \League\Flysystem\Filesystem($filesystemAdapter);

                    $pool = new FilesystemCachePool($filesystem);
                }

                if ($adapter == 'memcached') {
                    $host = (isset($poolConfig['host'])) ? $poolConfig['host'] : 'localhost';
                    $port = (isset($poolConfig['port'])) ? $poolConfig['port'] : 11211;

                    $client = new \Memcached();
                    $client->addServer($host, $port);
                    $pool = new MemcachedCachePool($client);
                }

                if ($adapter == 'redis') {
                    $host = (isset($poolConfig['host'])) ? $poolConfig['host'] : 'localhost';
                    $port = (isset($poolConfig['port'])) ? $poolConfig['port'] : 6379;

                    $client = new \Redis();
                    $client->connect($host, $port);
                    $pool = new RedisCachePool($client);
                }
            }

            return $pool;
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaAdapter()
    {
        return function (Container $container) {
            $adapter = $container->get('database');
            $databaseName = $adapter->getPlatform()->getName();

            switch ($databaseName) {
                case 'MySQL':
                    return new \Directus\Database\Schema\Sources\MySQLSchema($adapter);
                // case 'SQLServer':
                //    return new SQLServerSchema($adapter);
                // case 'SQLite':
                //     return new \Directus\Database\Schemas\Sources\SQLiteSchema($adapter);
                // case 'PostgreSQL':
                //     return new PostgresSchema($adapter);
            }

            throw new \Exception('Unknown/Unsupported database: ' . $databaseName);
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaManager()
    {
        return function (Container $container) {
            return new SchemaManager(
                $container->get('schema_adapter')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getSchemaFactory()
    {
        return function (Container $container) {
            return new SchemaFactory(
                $container->get('schema_manager')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getResponseCache()
    {
        return function (Container $container) {
            return new Response($container->get('cache'), $container->get('config')->get('cache.response_ttl'));
        };
    }

    /**
     * @return \Closure
     */
    protected function getHashManager()
    {
        return function (Container $container) {
            $hashManager = new HashManager();
            $basePath = $container->get('path_base');

            $path = implode(DIRECTORY_SEPARATOR, [
                $basePath,
                'custom',
                'hashers',
                '*.php'
            ]);

            $customHashersFiles = glob($path);
            $hashers = [];

            if ($customHashersFiles) {
                foreach ($customHashersFiles as $filename) {
                    $name = basename($filename, '.php');
                    // filename starting with underscore are skipped
                    if (StringUtils::startsWith($name, '_')) {
                        continue;
                    }

                    $hashers[] = '\\Directus\\Custom\\Hasher\\' . $name;
                }
            }

            foreach ($hashers as $hasher) {
                $hashManager->register(new $hasher());
            }

            return $hashManager;
        };
    }

    protected function getFileSystem()
    {
        return function (Container $container) {
            $config = $container->get('config');

            return new Filesystem(
                FilesystemFactory::createAdapter($config->get('filesystem'), 'root')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getThumbFilesystem()
    {
        return function (Container $container) {
            $config = $container->get('config');

            return new Filesystem(
                FilesystemFactory::createAdapter($config->get('filesystem'), 'root_thumb')
            );
        };
    }

    /**
     * @return \Closure
     */
    protected function getMailerTransportManager()
    {
        return function (Container $container) {
            $config = $container->get('config');
            $manager = new TransportManager();

            $transports = [
                'simple_file' => SimpleFileTransport::class,
                'smtp' => \Swift_SmtpTransport::class,
                'sendmail' => \Swift_SendmailTransport::class
            ];

            $mailConfigs = $config->get('mail');
            foreach ($mailConfigs as $name => $mailConfig) {
                $transport = ArrayUtils::get($mailConfig, 'transport');

                if (array_key_exists($transport, $transports)) {
                    $transport = $transports[$transport];
                }

                $manager->register($name, $transport, $mailConfig);
            }

            return $manager;
        };
    }

    /**
     * @return \Closure
     */
    protected function getMailer()
    {
        return function (Container $container) {
            return new Mailer($container->get('mailer_transport'));
        };
    }

    /**
     * @return \Closure
     */
    protected function getSettings()
    {
        return function (Container $container) {
            $dbConnection = $container->get('database');
            $settingsTable = new TableGateway(SchemaManager::COLLECTION_SETTINGS, $dbConnection);

            return $settingsTable->select()->toArray();
        };
    }

    /**
     * @return \Closure
     */
    protected function getStatusMapping()
    {
        return function (Container $container) {
            $settings = $container->get('app_settings');

            $statusMapping = [];
            foreach ($settings as $setting) {
                if (
                    ArrayUtils::get($setting, 'scope') == 'status'
                    && ArrayUtils::get($setting, 'key') == 'status_mapping'
                ) {
                    $statusMapping = json_decode($setting['value'], true);
                    break;
                }
            }

            if (!is_array($statusMapping)) {
                $statusMapping = [];
            }

            return new StatusMapping($statusMapping);
        };
    }

    /**
     * @return \Closure
     */
    protected function getMailView()
    {
        return function (Container $container) {
            $basePath = $container->get('path_base');

            return new Twig([
                $basePath . '/public/extensions/custom/mail',
                $basePath . '/src/mail'
            ]);
        };
    }

    /**
     * @return \Closure
     */
    protected function getFiles()
    {
        return function (Container $container) {
            $settings = $container->get('app_settings');

            // Convert result into a key-value array
            $filesSettings = [];
            foreach ($settings as $setting) {
                if ($setting['scope'] === 'files') {
                    $filesSettings[$setting['key']] = $setting['value'];
                }
            }

            $filesystem = $container->get('filesystem');
            $config = $container->get('config');
            $config = $config->get('filesystem', []);
            $emitter = $container->get('hook_emitter');

            return new Files(
                $filesystem,
                $config,
                $filesSettings,
                $emitter
            );
        };
    }

    protected function getEmbedManager()
    {
        return function (Container $container) {
            $app = Application::getInstance();
            $embedManager = new EmbedManager();
            $acl = $container->get('acl');
            $adapter = $container->get('database');

            // Fetch files settings
            $settingsTableGateway = new DirectusSettingsTableGateway($adapter, $acl);
            try {
                $settings = $settingsTableGateway->fetchItems([
                    'filter' => ['scope' => 'files']
                ]);
            } catch (\Exception $e) {
                $settings = [];
                /** @var Logger $logger */
                $logger = $container->get('logger');
                $logger->warning($e->getMessage());
            }

            $providers = [
                '\Directus\Embed\Provider\VimeoProvider',
                '\Directus\Embed\Provider\YoutubeProvider'
            ];

            $path = implode(DIRECTORY_SEPARATOR, [
                $app->getContainer()->get('path_base'),
                'custom',
                'embeds',
                '*.php'
            ]);

            $customProvidersFiles = glob($path);
            if ($customProvidersFiles) {
                foreach ($customProvidersFiles as $filename) {
                    $providers[] = '\\Directus\\Embed\\Provider\\' . basename($filename, '.php');
                }
            }

            foreach ($providers as $providerClass) {
                $provider = new $providerClass($settings);
                $embedManager->register($provider);
            }

            return $embedManager;
        };
    }

    /**
     * Register all services
     *
     * @param Container $mainContainer
     *
     * @return \Closure
     *
     * @internal param Container $container
     *
     */
    protected function getServices(Container $mainContainer)
    {
        // A services container of all Directus services classes
        return function () use ($mainContainer) {
            $container = new Container();

            // =============================================================================
            // Register all services
            // -----------------------------------------------------------------------------
            // TODO: Set a place to load all the services
            // =============================================================================
            $container['auth'] = $this->getAuthService($mainContainer);

            return $container;
        };
    }

    /**
     * @param Container $container Application container
     *
     * @return \Closure
     */
    protected function getAuthService(Container $container)
    {
        return function () use ($container) {
            return new AuthService($container);
        };
    }
}

