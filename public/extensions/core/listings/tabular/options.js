parcelRequire=function(e,r,n,t){var i="function"==typeof parcelRequire&&parcelRequire,o="function"==typeof require&&require;function u(n,t){if(!r[n]){if(!e[n]){var f="function"==typeof parcelRequire&&parcelRequire;if(!t&&f)return f(n,!0);if(i)return i(n,!0);if(o&&"string"==typeof n)return o(n);var c=new Error("Cannot find module '"+n+"'");throw c.code="MODULE_NOT_FOUND",c}p.resolve=function(r){return e[n][1][r]||r};var l=r[n]=new u.Module(n);e[n][0].call(l.exports,p,l,l.exports,this)}return r[n].exports;function p(e){return u(p.resolve(e))}}u.isParcelRequire=!0,u.Module=function(e){this.id=e,this.bundle=u,this.exports={}},u.modules=e,u.cache=r,u.parent=i,u.register=function(r,n){e[r]=[function(e,r){r.exports=n},{}]};for(var f=0;f<n.length;f++)u(n[f]);if(n.length){var c=u(n[n.length-1]);"object"==typeof exports&&"undefined"!=typeof module?module.exports=c:"function"==typeof define&&define.amd?define(function(){return c}):t&&(this[t]=c)}return u}({317:[function(require,module,exports) {
module.exports={props:{primaryKeyField:{type:String,required:!0},fields:{type:Object,required:!0},items:{type:Array,default:function(){return[]}},viewOptions:{type:Object,default:function(){return{}}},viewQuery:{type:Object,default:function(){return{}}},loading:{type:Boolean,default:!1},lazyLoading:{type:Boolean,default:!1},selection:{type:Array,default:function(){return[]}},link:{type:String,default:null}}};
},{}],62:[function(require,module,exports) {
"use strict";Object.defineProperty(exports,"__esModule",{value:!0});var i=Object.assign||function(i){for(var t=1;t<arguments.length;t++){var e=arguments[t];for(var n in e)Object.prototype.hasOwnProperty.call(e,n)&&(i[n]=e[n])}return i},t=require("../../../mixins/listing"),e=n(t);function n(i){return i&&i.__esModule?i:{default:i}}function r(i){if(Array.isArray(i)){for(var t=0,e=Array(i.length);t<i.length;t++)e[t]=i[t];return e}return Array.from(i)}exports.default={mixins:[e.default],data:function(){return{sortList:null}},computed:{fieldsInUse:function(){var i=this;return this.viewQuery&&this.viewQuery.fields?""===this.viewQuery.fields?[]:this.viewQuery.fields.split(",").filter(function(t){return i.fields[t]}):Object.keys(this.fields)}},created:function(){this.initSortList()},methods:{setSpacing:function(i){this.$emit("options",{spacing:i})},toggleField:function(i){var t=[].concat(r(this.fieldsInUse));t.includes(i)?t.splice(t.indexOf(i),1):t.push(i);var e=this.sortList.map(function(i){return i.field}).filter(function(i){return t.includes(i)}).join();this.$emit("query",{fields:e})},sort:function(){var t=this;this.$emit("query",i({},this.viewQuery,{fields:this.sortList.map(function(i){return i.field}).filter(function(i){return t.fieldsInUse.includes(i)}).join()}))},initSortList:function(){var i=this;this.sortList=[].concat(r(this.fieldsInUse.map(function(t){return i.fields[t]})),r(Object.values(this.fields).filter(function(t){return!i.fieldsInUse.includes(t.field)})))}},watch:{fields:function(){this.initSortList()}}};
(function(){var e=exports.default||module.exports;"function"==typeof e&&(e=e.options),Object.assign(e,{render:function(){var e=this,t=e.$createElement,s=e._self._c||t;return s("form",{on:{submit:function(e){e.preventDefault()}}},[s("fieldset",[s("legend",[e._v(e._s(e.$t("listings-tabular-fields")))]),e._v(" "),s("draggable",{on:{end:e.sort},model:{value:e.sortList,callback:function(t){e.sortList=t},expression:"sortList"}},e._l(e.sortList,function(t){return s("div",{staticClass:"draggable"},[s("v-checkbox",{key:t.field,staticClass:"checkbox",attrs:{id:t.field,label:t.name,value:t.field,checked:e.fieldsInUse.includes(t.field)},on:{change:function(s){e.toggleField(t.field)}}},[s("i",{staticClass:"material-icons"},[e._v("drag_handle")])])],1)}))],1),e._v(" "),s("label",{attrs:{for:"spacing"}},[e._v("Spacing")]),e._v(" "),s("v-select",{staticClass:"select",attrs:{id:"spacing",value:e.viewOptions.spacing||"cozy",options:{compact:"Compact",cozy:"Cozy",comfortable:"Comfortable"},icon:"reorder"},on:{input:e.setSpacing}})],1)},staticRenderFns:[],_compiled:!0,_scopeId:"data-v-f11625",functional:void 0});})();
},{"../../../mixins/listing":317}]},{},[62], "__DirectusExtension__")