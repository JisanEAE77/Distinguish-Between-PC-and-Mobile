<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title>devtools-detector demo</title>
    <style>
      #text,
      #checker {
        font-size: 30px;
        margin-top: 50px;
        text-align: center;
      }
      #checker {
        font-size: 16px;
        margin-top: 10px;
        color: brown;
      }
    </style>
    <script>
        (function (global, factory) {
        typeof exports === 'object' && typeof module !== 'undefined' ? factory() :
        typeof define === 'function' && define.amd ? define(factory) :
        (factory());
    }(this, (function () { 'use strict';
    
    /**
     * @this {Promise}
     */
    function finallyConstructor(callback) {
      var constructor = this.constructor;
      return this.then(
        function(value) {
          return constructor.resolve(callback()).then(function() {
            return value;
          });
        },
        function(reason) {
          return constructor.resolve(callback()).then(function() {
            return constructor.reject(reason);
          });
        }
      );
    }
    
    // Store setTimeout reference so promise-polyfill will be unaffected by
    // other code modifying setTimeout (like sinon.useFakeTimers())
    var setTimeoutFunc = setTimeout;
    
    function noop() {}
    
    // Polyfill for Function.prototype.bind
    function bind(fn, thisArg) {
      return function() {
        fn.apply(thisArg, arguments);
      };
    }
    
    /**
     * @constructor
     * @param {Function} fn
     */
    function Promise(fn) {
      if (!(this instanceof Promise))
        throw new TypeError('Promises must be constructed via new');
      if (typeof fn !== 'function') throw new TypeError('not a function');
      /** @type {!number} */
      this._state = 0;
      /** @type {!boolean} */
      this._handled = false;
      /** @type {Promise|undefined} */
      this._value = undefined;
      /** @type {!Array<!Function>} */
      this._deferreds = [];
    
      doResolve(fn, this);
    }
    
    function handle(self, deferred) {
      while (self._state === 3) {
        self = self._value;
      }
      if (self._state === 0) {
        self._deferreds.push(deferred);
        return;
      }
      self._handled = true;
      Promise._immediateFn(function() {
        var cb = self._state === 1 ? deferred.onFulfilled : deferred.onRejected;
        if (cb === null) {
          (self._state === 1 ? resolve : reject)(deferred.promise, self._value);
          return;
        }
        var ret;
        try {
          ret = cb(self._value);
        } catch (e) {
          reject(deferred.promise, e);
          return;
        }
        resolve(deferred.promise, ret);
      });
    }
    
    function resolve(self, newValue) {
      try {
        
        if (newValue === self)
          throw new TypeError('A promise cannot be resolved with itself.');
        if (
          newValue &&
          (typeof newValue === 'object' || typeof newValue === 'function')
        ) {
          var then = newValue.then;
          if (newValue instanceof Promise) {
            self._state = 3;
            self._value = newValue;
            finale(self);
            return;
          } else if (typeof then === 'function') {
            doResolve(bind(then, newValue), self);
            return;
          }
        }
        self._state = 1;
        self._value = newValue;
        finale(self);
      } catch (e) {
        reject(self, e);
      }
    }
    
    function reject(self, newValue) {
      self._state = 2;
      self._value = newValue;
      finale(self);
    }
    
    function finale(self) {
      if (self._state === 2 && self._deferreds.length === 0) {
        Promise._immediateFn(function() {
          if (!self._handled) {
            Promise._unhandledRejectionFn(self._value);
          }
        });
      }
    
      for (var i = 0, len = self._deferreds.length; i < len; i++) {
        handle(self, self._deferreds[i]);
      }
      self._deferreds = null;
    }
    
    /**
     * @constructor
     */
    function Handler(onFulfilled, onRejected, promise) {
      this.onFulfilled = typeof onFulfilled === 'function' ? onFulfilled : null;
      this.onRejected = typeof onRejected === 'function' ? onRejected : null;
      this.promise = promise;
    }
    
    /**
     * Take a potentially misbehaving resolver function and make sure
     * onFulfilled and onRejected are only called once.
     *
     * Makes no guarantees about asynchrony.
     */
    function doResolve(fn, self) {
      var done = false;
      try {
        fn(
          function(value) {
            if (done) return;
            done = true;
            resolve(self, value);
          },
          function(reason) {
            if (done) return;
            done = true;
            reject(self, reason);
          }
        );
      } catch (ex) {
        if (done) return;
        done = true;
        reject(self, ex);
      }
    }
    
    Promise.prototype['catch'] = function(onRejected) {
      return this.then(null, onRejected);
    };
    
    Promise.prototype.then = function(onFulfilled, onRejected) {
      // @ts-ignore
      var prom = new this.constructor(noop);
    
      handle(this, new Handler(onFulfilled, onRejected, prom));
      return prom;
    };
    
    Promise.prototype['finally'] = finallyConstructor;
    
    Promise.all = function(arr) {
      return new Promise(function(resolve, reject) {
        if (!arr || typeof arr.length === 'undefined')
          throw new TypeError('Promise.all accepts an array');
        var args = Array.prototype.slice.call(arr);
        if (args.length === 0) return resolve([]);
        var remaining = args.length;
    
        function res(i, val) {
          try {
            if (val && (typeof val === 'object' || typeof val === 'function')) {
              var then = val.then;
              if (typeof then === 'function') {
                then.call(
                  val,
                  function(val) {
                    res(i, val);
                  },
                  reject
                );
                return;
              }
            }
            args[i] = val;
            if (--remaining === 0) {
              resolve(args);
            }
          } catch (ex) {
            reject(ex);
          }
        }
    
        for (var i = 0; i < args.length; i++) {
          res(i, args[i]);
        }
      });
    };
    
    Promise.resolve = function(value) {
      if (value && typeof value === 'object' && value.constructor === Promise) {
        return value;
      }
    
      return new Promise(function(resolve) {
        resolve(value);
      });
    };
    
    Promise.reject = function(value) {
      return new Promise(function(resolve, reject) {
        reject(value);
      });
    };
    
    Promise.race = function(values) {
      return new Promise(function(resolve, reject) {
        for (var i = 0, len = values.length; i < len; i++) {
          values[i].then(resolve, reject);
        }
      });
    };
    
    // Use polyfill for setImmediate for performance gains
    Promise._immediateFn =
      (typeof setImmediate === 'function' &&
        function(fn) {
          setImmediate(fn);
        }) ||
      function(fn) {
        setTimeoutFunc(fn, 0);
      };
    
    Promise._unhandledRejectionFn = function _unhandledRejectionFn(err) {
      if (typeof console !== 'undefined' && console) {
        console.warn('Possible Unhandled Promise Rejection:', err); // eslint-disable-line no-console
      }
    };
    
    /** @suppress {undefinedVars} */
    var globalNS = (function() {
      // the only reliable means to get the global object is
      // `Function('return this')()`
      // However, this causes CSP violations in Chrome apps.
      if (typeof self !== 'undefined') {
        return self;
      }
      if (typeof window !== 'undefined') {
        return window;
      }
      if (typeof global !== 'undefined') {
        return global;
      }
      throw new Error('unable to locate global object');
    })();
    
    if (!('Promise' in globalNS)) {
      globalNS['Promise'] = Promise;
    } else if (!globalNS.Promise.prototype['finally']) {
      globalNS.Promise.prototype['finally'] = finallyConstructor;
    }
    
    })));
    
    </script>
    <script>
        !function(t,e){"object"==typeof exports&&"object"==typeof module?module.exports=e():"function"==typeof define&&define.amd?define([],e):"object"==typeof exports?exports.devtoolsDetector=e():t.devtoolsDetector=e()}("undefined"!=typeof self?self:this,function(){return function(t){var e={};function n(r){if(e[r])return e[r].exports;var o=e[r]={i:r,l:!1,exports:{}};return t[r].call(o.exports,o,o.exports,n),o.l=!0,o.exports}return n.m=t,n.c=e,n.d=function(t,e,r){n.o(t,e)||Object.defineProperty(t,e,{configurable:!1,enumerable:!0,get:r})},n.n=function(t){var e=t&&t._esModule?function(){return t.default}:function(){return t};return n.d(e,"a",e),e},n.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},n.p="",n(n.s=10)}([function(t,e,n){"use strict";e.a=function(t,e,n){return r({},n,{name:e||"unknow group",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){var n,r,o,u,c;return i(this,function(i){switch(i.label){case 0:n=0,r=t,i.label=1;case 1:return n<r.length?(o=r[n],(u=o.skip)?[4,o.skip()]:[3,3]):[3,6];case 2:u=i.sent(),i.label=3;case 3:return u?[3,5]:[4,o.getDevtoolsDetail()];case 4:if((c=i.sent()).isOpen||c.directReturn)return e&&(c.checkerName=e+"."+c.checkerName),[2,c];i.label=5;case 5:return n++,[3,1];case 6:return[2,{checkerName:this.name,isOpen:!1}]}})})}})};var r=this&&this.assign||function(){return(r=Object.assign||function(t){for(var e,n=1,r=arguments.length;n<r;n++)for(var o in e=arguments[n])Object.prototype.hasOwnProperty.call(e,o)&&(t[o]=e[o]);return t}).apply(this,arguments)},o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}}},function(t,e,n){"use strict";n.d(e,"b",function(){return i}),n.d(e,"c",function(){return u}),n.d(e,"a",function(){return c}),n.d(e,"d",function(){return a});var r=n(6),o=navigator.userAgent,i=Object(r.a)(function(){return o.indexOf("Firefox")>-1}),u=Object(r.a)(function(){return o.indexOf("Trident")>-1||o.indexOf("MSIE")>-1}),c=Object(r.a)(function(){return o.indexOf("Edge")>-1}),a=Object(r.a)(function(){return/webkit/i.test(o)&&!c()})},function(t,e,n){"use strict";n.d(e,"b",function(){return u}),n.d(e,"c",function(){return c}),n.d(e,"a",function(){return a});var r=n(1),o=function(t){return"function"==typeof t};function i(t){if(console){var e=console[t];if(o(e))return r.c||r.a?function(){for(var e=[],n=0;n<arguments.length;n++)e[n]=arguments[n];console[t].apply(console,e)}:console[t]}return function(){for(var t=[],e=0;e<arguments.length;e++)t[e]=arguments[e]}}var u=i("log"),c=i("table"),a=i("clear")},function(t,e,n){"use strict";var r=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},o=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}};function i(){return performance?performance.now():Date.now()}var u={name:"debugger-checker",getDevtoolsDetail:function(){return r(this,void 0,void 0,function(){var t;return o(this,function(e){return t=i(),[2,{isOpen:i()-t>100,checkerName:this.name}]})})}};e.a=u},function(t,e,n){"use strict";n.d(e,"a",function(){return u});var r=n(0),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},u=function(){function t(t){var e=t.checkers;this._listeners=[],this._isOpen=!1,this._detectLoopStoped=!0,this._detectLoopDelay=500,this._checker=Object(r.a)(e)}return t.prototype.lanuch=function(){this._detectLoopDelay<=0&&this.setDetectDelay(500),this._detectLoopStoped&&(this._detectLoopStoped=!1,this._detectLoop())},t.prototype.stop=function(){this._detectLoopStoped||(this._detectLoopStoped=!0,clearTimeout(this._timer))},t.prototype.isLanuch=function(){return!this._detectLoopStoped},t.prototype.setDetectDelay=function(t){this._detectLoopDelay=t},t.prototype.addListener=function(t){this._listeners.push(t)},t.prototype.removeListener=function(t){this._listeners=this._listeners.filter(function(e){return e!==t})},t.prototype._broadcast=function(t){for(var e=0,n=this._listeners;e<n.length;e++){var r=n[e];try{r(t.isOpen,t)}catch(t){}}},t.prototype._detectLoop=function(){return o(this,void 0,void 0,function(){var t,e=this;return i(this,function(n){switch(n.label){case 0:return[4,this._checker.getDevtoolsDetail()];case 1:return(t=n.sent()).isOpen!=this._isOpen&&(this._isOpen=t.isOpen,this._broadcast(t)),this._detectLoopDelay>0?this._timer=setTimeout(function(){return e._detectLoop()},this._detectLoopDelay):this.stop(),[2]}})})},t}()},function(t,e,n){"use strict";var r=n(0),o=n(11),i=n(13),u=n(14),c=Object(r.a)([o.a,i.a,u.a],"console-checker");e.a=c},function(t,e,n){"use strict";e.a=function(t){var e,n=!1;return function(){for(var r=[],o=0;o<arguments.length;o++)r[o]=arguments[o];return n?e:(n=!0,e=t.apply(void 0,r))}}},function(t,e,n){"use strict";var r=n(2),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},u=/ /,c=!1;u.toString=function(){return c=!0,a.name};var a={name:"reg-toString-checker",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){return i(this,function(t){return c=!1,Object(r.b)(u),Object(r.a)(),[2,{isOpen:c,checkerName:this.name}]})})}};e.a=a},function(t,e,n){"use strict";var r=n(2),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},u=document.createElement("div"),c=!1;Object.defineProperty(u,"id",{get:function(){return c=!0,a.name},configurable:!0});var a={name:"element-id-chekcer",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){return i(this,function(t){return c=!1,Object(r.b)(u),Object(r.a)(),[2,{isOpen:c,checkerName:this.name}]})})}};e.a=a},function(t,e,n){"use strict";var r=n(17),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},u={name:"firebug-checker",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){var t,e;return i(this,function(n){t=window.top,e=!1;try{e=t.Firebug&&t.Firebug.chrome&&t.Firebug.chrome.isInitialized}catch(t){}return[2,{isOpen:e,checkerName:this.name}]})})},skip:function(){return o(this,void 0,void 0,function(){return i(this,function(t){return[2,Object(r.a)()]})})}};e.a=u},function(t,e,n){"use strict";Object.defineProperty(e,"esModule",{value:!0}),e.addListener=function(t){c.addListener(t)},e.removeListener=function(t){c.removeListener(t)},e.isLanuch=function(){return c.isLanuch()},e.stop=function(){c.stop()},e.lanuch=function(){c.lanuch()},e.setDetectDelay=function(t){c.setDetectDelay(t)};var r=n(4),o=n(5),i=n(3),u=n(9);n.d(e,"consoleChecker",function(){return o.a}),n.d(e,"debuggerChecker",function(){return i.a}),n.d(e,"firebugChecker",function(){return u.a}),n.d(e,"Detector",function(){return r.a});var c=new r.a({checkers:[u.a,o.a,i.a]})},function(t,e,n){"use strict";var r=n(1),o=n(0),i=n(3),u=n(12),c=n(7),a=this&&this.assign||function(){return(a=Object.assign||function(t){for(var e,n=1,r=arguments.length;n<r;n++)for(var o in e=arguments[n])Object.prototype.hasOwnProperty.call(e,o)&&(t[o]=e[o]);return t}).apply(this,arguments)},l=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},s=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},f=a({},Object(u.a)(Object(o.a)([c.a,i.a])),{name:"firefox-checker",skip:function(){return l(this,void 0,void 0,function(){return s(this,function(t){return[2,!Object(r.b)()]})})}});e.a=f},function(t,e,n){"use strict";e.a=function(t){return r({},t,{getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){var e;return i(this,function(n){switch(n.label){case 0:return[4,t.getDevtoolsDetail()];case 1:return(e=n.sent()).directReturn=!0,[2,e]}})})}})};var r=this&&this.assign||function(){return(r=Object.assign||function(t){for(var e,n=1,r=arguments.length;n<r;n++)for(var o in e=arguments[n])Object.prototype.hasOwnProperty.call(e,o)&&(t[o]=e[o]);return t}).apply(this,arguments)},o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}}},function(t,e,n){"use strict";var r=n(1),o=n(8),i=this&&this.assign||function(){return(i=Object.assign||function(t){for(var e,n=1,r=arguments.length;n<r;n++)for(var o in e=arguments[n])Object.prototype.hasOwnProperty.call(e,o)&&(t[o]=e[o]);return t}).apply(this,arguments)},u=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},c=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},a=i({},o.a,{name:"ie-edge-checker",skip:function(){return u(this,void 0,void 0,function(){return c(this,function(t){return[2,!(Object(r.c)()||Object(r.a)())]})})}});e.a=a},function(t,e,n){"use strict";var r=n(1),o=n(0),i=n(15),u=n(8),c=n(16),a=this&&this.assign||function(){return(a=Object.assign||function(t){for(var e,n=1,r=arguments.length;n<r;n++)for(var o in e=arguments[n])Object.prototype.hasOwnProperty.call(e,o)&&(t[o]=e[o]);return t}).apply(this,arguments)},l=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},s=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},f=a({},Object(o.a)([u.a,c.a,i.a]),{name:"webkit-checker",skip:function(){return l(this,void 0,void 0,function(){return s(this,function(t){return[2,!Object(r.d)()]})})}});e.a=f},function(t,e,n){"use strict";var r=n(2),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this.generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}},u=/ /,c=!1;u.toString=function(){return c=!0,a.name};var a={name:"dep-reg-toString-checker",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){return i(this,function(t){return c=!1,Object(r.c)({dep:u}),Object(r.a)(),[2,{isOpen:c,checkerName:this.name}]})})}};e.a=a},function(t,e,n){"use strict";var r=n(2),o=this&&this.awaiter||function(t,e,n,r){return new(n||(n=Promise))(function(o,i){function u(t){try{a(r.next(t))}catch(t){i(t)}}function c(t){try{a(r.throw(t))}catch(t){i(t)}}function a(t){t.done?o(t.value):new n(function(e){e(t.value)}).then(u,c)}a((r=r.apply(t,e||[])).next())})},i=this&&this._generator||function(t,e){var n,r,o,i,u={label:0,sent:function(){if(1&o[0])throw o[1];return o[1]},trys:[],ops:[]};return i={next:c(0),throw:c(1),return:c(2)},"function"==typeof Symbol&&(i[Symbol.iterator]=function(){return this}),i;function c(i){return function(c){return function(i){if(n)throw new TypeError("Generator is already executing.");for(;u;)try{if(n=1,r&&(o=2&i[0]?r.return:i[0]?r.throw||((o=r.return)&&o.call(r),0):r.next)&&!(o=o.call(r,i[1])).done)return o;switch(r=0,o&&(i=[2&i[0],o.value]),i[0]){case 0:case 1:o=i;break;case 4:return u.label++,{value:i[1],done:!1};case 5:u.label++,r=i[1],i=[0];continue;case 7:i=u.ops.pop(),u.trys.pop();continue;default:if(!(o=(o=u.trys).length>0&&o[o.length-1])&&(6===i[0]||2===i[0])){u=0;continue}if(3===i[0]&&(!o||i[1]>o[0]&&i[1]<o[3])){u.label=i[1];break}if(6===i[0]&&u.label<o[1]){u.label=o[1],o=i;break}if(o&&u.label<o[2]){u.label=o[2],u.ops.push(i);break}o[2]&&u.ops.pop(),u.trys.pop();continue}i=e.call(t,u)}catch(t){i=[6,t],r=0}finally{n=o=0}if(5&i[0])throw i[1];return{value:i[0]?i[1]:void 0,done:!0}}([i,c])}}};function u(){}var c=0;u.toString=function(){c++};var a={name:"function-to-string-checker",getDevtoolsDetail:function(){return o(this,void 0,void 0,function(){return i(this,function(t){return c=0,Object(r.b)(u),Object(r.a)(),[2,{isOpen:2===c,checkerName:this.name}]})})}};e.a=a},function(t,e,n){"use strict";n.d(e,"a",function(){return i});var r=n(6),o=Object(r.a)(function(){return window.top!==window}),i=Object(r.a)(function(){if(!o())return!1;try{return Object.keys(window.top.innerWidth),!1}catch(t){return!0}})}])});
    
    </script>
  </head>

  <body>
    <noscript>
        script is disabled in this browser
    </noscript>
    
    <script>
    var getHasLiedBrowser = function () {
    var userAgent = navigator.userAgent.toLowerCase()
    var productSub = navigator.productSub

    // we extract the browser from the user agent (respect the order of the tests)
    var browser
    if (userAgent.indexOf('edge/') >= 0 || userAgent.indexOf('iemobile/') >= 0) {
      // Unreliable, different versions use EdgeHTML, Webkit, Blink, etc.
      return false
    } else if (userAgent.indexOf('opera mini') >= 0) {
      // Unreliable, different modes use Presto, WebView, Webkit, etc.
      return false
    } else if (userAgent.indexOf('firefox/') >= 0) {
      browser = 'Firefox'
    } else if (userAgent.indexOf('opera/') >= 0 || userAgent.indexOf(' opr/') >= 0) {
      browser = 'Opera'
    } else if (userAgent.indexOf('chrome/') >= 0) {
      browser = 'Chrome'
    } else if (userAgent.indexOf('safari/') >= 0) {
      if (userAgent.indexOf('android 1.') >= 0 || userAgent.indexOf('android 2.') >= 0 || userAgent.indexOf('android 3.') >= 0 || userAgent.indexOf('android 4.') >= 0) {
        browser = 'AOSP'
      } else {
        browser = 'Safari'
      }
    } else if (userAgent.indexOf('trident/') >= 0) {
      browser = 'Internet Explorer'
    } else {
      browser = 'Other'
    }

    if ((browser === 'Chrome' || browser === 'Safari' || browser === 'Opera') && productSub !== '20030107') {
      return true
    }

    // eslint-disable-next-line no-eval
    var tempRes = eval.toString().length
    if (tempRes === 37 && browser !== 'Safari' && browser !== 'Firefox' && browser !== 'Other') {
      return true
    } else if (tempRes === 39 && browser !== 'Internet Explorer' && browser !== 'Other') {
      return true
    } else if (tempRes === 33 && browser !== 'Chrome' && browser !== 'AOSP' && browser !== 'Opera' && browser !== 'Other') {
      return true
    }

    // We create an error to see how it is handled
    var errFirefox
    try {
      // eslint-disable-next-line no-throw-literal
      throw 'a'
    } catch (err) {
      try {
        err.toSource()
        errFirefox = true
      } catch (errOfErr) {
        errFirefox = false
      }
    }
    return errFirefox && browser !== 'Firefox' && browser !== 'Other'
  }
      function isTouchDevice() {
        var match = window.matchMedia || window.msMatchMedia;
        if(match) {
            var mq = match("(pointer:coarse)");
            return mq.matches;
        }
        return false;
      }
      const isBrowserSimulation = () => {
        console.log(isTouchDevice(),navigator.maxTouchPoints === 1,getHasLiedBrowser(),window.navigator.userAgent.indexOf('Mobile'))
    // maxTouchPoints is always = 1 on simulation mode
        return (
              isTouchDevice() && navigator.maxTouchPoints === 1 && (getHasLiedBrowser() || window.navigator.userAgent.indexOf('Mobile') !== -1)
          );
      };

      if (navigator.maxTouchPoints < 2) {
      document.querySelector("body").remove();
      alert("Use a mobile device to visit this site");
      }

      if (navigator.plugins.length > 0) {
      document.querySelector("body").remove();
      alert("Use a mobile device to visit this site");
      }

      if (isBrowserSimulation()){
        document.querySelector("body").remove();
        alert("Use a mobile device to visit this site");
      }
      devtoolsDetector.addListener(function(isOpen, detail) {
        if (isOpen) {
            document.querySelector("body").remove();
            alert("Use a mobile device to visit this site");
        } else {
        }
      });
      devtoolsDetector.lanuch();
    </script>
    <?php
    function isMobileDevice() {
        return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
    }
    
    


        if(isMobileDevice()) {
            echo "<h2 style='color: green;'>Congratulation! as you are using a original mobile device you can access this website.</h2>";
        } else {
            echo "<h2 style='color: red;'>You are not allowed to access this website as you are not using an original mobile device. Minimizing your browser size on big devices can't give you permission to access this website. So use an original mobile device to access this website.</h2>";
        }
        
 
?>
  </body>
</html>
