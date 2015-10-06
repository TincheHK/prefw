/*! angular-faye.js | Faye service for Angular. */

(function() {
  var app = angular.module('faye', []);

  app.factory('$faye', function($q, $rootScope) {
    return function(url) {
      var client = new Faye.Client(url);

      return {
        client: client,
        publish: function(channel, data) {
          return this.client.publish(channel, data);
        },
        subscribe: function(channel, callback) {
          return this.client.subscribe(channel, function(data) {
            $rootScope.$apply(callback.bind(this, data));
          });
        },
        get: function(channel) {
          var $d = $q.defer()
            , subscription;

          subscription = this.client.subscribe(channel, function(data) {
            subscription.cancel();
            $d.resolve(data);
          });

          return $d.promise;
        }
      };
    };
  });
}).call(this);
