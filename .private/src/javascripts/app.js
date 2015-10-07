/*! app.js | Main script file for the system. */

;(function() {'use strict';

//------------------------------------------------------------------------------
//
//  Initialize
//
//------------------------------------------------------------------------------

var app = angular.module('prefwApp', ['ngRoute', 'ngResource', 'ngCookies', 'ngIncludeTemplate', 'cfp.hotkeys', 'cgNotify', 'faye', 'schemaForm', 'yaru22.angular-timeago', 'ui.sortable']);

app.filter('infiniteLoad', function() {
  return function(input, minLength, callback) {
    if ( angular.isArray(input) && input.length <= minLength ) {
      callback(input);
    }

    return input;
  };
});

/*! Faye.js
 *  Client connection and basic setup
 */
app.factory('$bayeux', function($faye) {
  return $faye('//prefw.dev:8080');
});

/*! parseTimestamp
 *  Global timestamp parser for data models.
 */
app.factory('parseTimestamp', function() {
  return function(item) {
    if ( angular.isArray(item) ) {
      return item.map(convertTimestamp);
    }
    else {
      return convertTimestamp(item);
    }

    function convertTimestamp(item) {
      if ( item.timestamp ) {
        item.timestamp = new Date(item.timestamp);
      }

      return item;
    }
  };
});

/*! parseNextTask
 *  Read about next task info.
 */
app.factory('parseNextTask', function() {
  return function(item) {
    if ( angular.isArray(item) ) {
      return item.map(convertNextTask);
    }
    else {
      return convertNextTask(item);
    }

    function convertNextTask(instance) {
      var tasks = instance.tasks || []
        , n = tasks.length;

      instance.__nextIndex = 0;

      for ( var i=0; i<n; i++ ) {
        if ( tasks[i].uuid == instance.nextTask ) {
          instance.__nextIndex = i;
          instance.__nextTask = tasks[i];
          instance.__tasksBegin = Math.max(i-1, 0);
          instance.__tasksLength = 3;
          break;
        }
      }

      return instance;
    };
  }
})

/**
 * Default resource actions for schema models.
 */
app.factory('defaultActions', function(parseTimestamp) {
  return {
      get: { method: 'GET', transformResponse: [ fromJson, parseTimestamp ] }
    , query: { method: 'GET', transformResponse: [ fromJson, parseTimestamp ], isArray: true }
    , save: { method: 'POST', transformResponse: [ fromJson, parseTimestamp ] }
    , dataSchema: { method: 'GET', params: { id: 'schema', type: 'data' }, cache: true }
    , formSchema: { method: 'GET', params: { id: 'schema', type: 'form' }, cache: true, isArray: true }
    };

  function fromJson(response) {
    try {
      return angular.fromJson(response);
    }
    catch (e) {
      return response;
    }
  }
});

/*! model: Work
 *  For WorkInstance creation at home, or work flow CRUD in control panel.
 */
app.factory('Work', function($resource, defaultActions) {
  return $resource('/service/_/Work/:id/:type', { id: '@id' }, defaultActions);
});

/*! model: WorkInstance
 *  Home, normal workflow actions.
 */
app.factory('WorkInstance', function($resource, defaultActions, parseNextTask) {
  defaultActions.get.transformResponse.push(parseNextTask);
  defaultActions.query.transformResponse.push(parseNextTask);
  defaultActions.save.transformResponse.push(parseNextTask);

  return $resource('/service/_/WorkInstance/:id/:type', { id: '@id' }, defaultActions);
});

/*! model: Task
 *  Task model for control panel, work flow and tasks page.
 */
app.factory('Task', function($resource, defaultActions) {
  return $resource('/service/_/Task/:id/:type', { id: '@id' }, defaultActions);
});

/*! model: User
 *  User model for control panels and user account page.
 */
app.factory('User', function($resource, defaultActions) {
  return $resource('/service/_/User/:id/:type', { id: '@id' }, defaultActions);
});

/*! model: Role
 *  This model is only queried for auto completion.
 */
app.factory('Role', function($resource) {
  return $resource('/service/_/Role/:id', { id: '@id' });
});

/*! Configuration
 *  Initialization process that do not require application context (object definitions).
 */
app.config(function appConfig($routeProvider, $locationProvider, $httpProvider, $controllerProvider, hotkeysProvider, notifyProvider) {
  $routeProvider
    .when('/', {
      controller: 'HomeCtrl',
      templateUrl: '/assets/templates/home.html'
    })
    .when('/task/new/:workId', {
      controller: 'TaskCtrl',
      templateUrl: '/assets/templates/newtask.html'
    })
    .when('/task/:uuid', {
      controller: 'TaskCtrl',
      templateUrl: '/assets/templates/task.html'
    })

    .when('/account', {
      controller: 'AccountCtrl',
      templateUrl: '/assets/templates/account.html'
    })

    .when('/login', {
      controller: 'LoginCtrl',
      templateUrl: '/assets/templates/login.html'
    })
    .when('/logout', {
      controller: 'LogoutCtrl',
      template: 'Logging out ...'
    })

    .when('/admin', { redirectTo: '/admin/workflows' })
    .when('/admin/:path*',
      { controller: 'AdminCtrl'
      , templateUrl: function($routeParams) {
          var paths = $routeParams.path.split('/')
            , path = 'assets/templates/admin/' + paths.shift();

          if ( paths.length ) {
            path+= '.item';
          }

          return path + '.html';
        }
    })

    .otherwise({ redirectTo: '/' });

  $locationProvider.html5Mode(true);

  // global error handler
  $httpProvider.interceptors.push(function($q, $injector) {
    return {
      response: function(response) {
        var data = response.data
          , notify = $injector.get('notify');

        if ( angular.isObject(data) && data.success ) {
          notify({
            message: data.success,
            classes: 'alert-success',
            duration: 10000
          });
        }

        return response;
      },
      responseError: function(response) {
        var data = response.data
          , notify = $injector.get('notify');

        if ( response.status != 404 ) {
          notify({
            message: data.error || 'Network error, please try again later.',
            classes: data.error ? 'alert-danger' : 'alert-warning',
            duration: 10000
          });
        }

        return $q.reject(response);
      }
    }
  });

  $httpProvider.defaults.paramSerializer = '$httpParamSerializerJQLike';

  hotkeysProvider.includeCheatSheet = false;

  notifyProvider.setOptions({
    startTop: 70,
    position: 'right'
  });

  // Global controller regsitration
  app.controller = $controllerProvider.register;

  // Bust the cachebuster
  // note; This is meant to cache task controllers, they should only be loaded once.
  $.ajaxSetup({ cache: true });
});

/*! Run
 *  Setup process that requires the application context (object instances).
 */
app.run(function appRun($injector, $q, $timeout, $http, $cookies, $bayeux, $rootScope, $location, $route, $filter) {
  // initial view states
  var $rs = $rootScope.states = {
    title: 'prefw',
    filter: {},

    // note: When resource collections has it's length < [0] || > [1], refresh the list.
    listThreshold: [10, 50]
  };

  // current user session
  $rs.user = $injector.get('User').get({ id: '~' },
    function onUser() {
      if ( $rs.__prev ) {
        $location.path($rs.__prev).replace();
        $route.reload();
        delete $rs.__prev;
      }
    },
    function noUser() {
      delete $rs.user;
      $location.path('/login').replace();
    });

  // todo; heartbeat session check

  $rootScope.$on('$locationChangeStart', function(evt, next, current) {
    var baseUrl = $location.absUrl().replace(
      new RegExp($location.url().replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + '$'), '');

    next = next.replace(baseUrl, '');
    current = current.replace(baseUrl, '');

    if ( !$rs.user || !$rs.user.$resolved ) {
      if ( next != '/login' ) {
        $rs.__prev = next;
        evt.preventDefault();
      }
    }

    // noUser: redirect back to login
    if ( !$rs.user ) {
      // note; prevent redirect loop
      if ( next !== '/login' ) {
        $location.path('/login').replace();
      }
    }
  });

  var bayeuxChannels = [];
  $rootScope.$watchCollection('states.user.groups', function(userGroups) {
    // note: reset everything in the previous login, if exists.
    if ( !userGroups || !userGroups.length ) {
      bayeuxChannels.forEach(function(channel) {
        console.info('Unsubscribing channel: %s', channel._channels.replace(/^\/group\//, ''));
        channel.cancel();
      });

      bayeuxChannels = [];

      return;
    }

    angular.forEach(userGroups || [], function(group) {
      console.info('Listening to group %s', group);

      bayeuxChannels.push(
          $bayeux.subscribe('/group/' + group, onBayeuxMessage)
        );
    });

    // note: this might not need to be reset
    var $timers = {};

    /*! Bayeux message update
     *
     *  Messages are merely update notices, and it is the client who reacts to the
     *  update event.
     *
     *  Update events consist of three main sections:
     *  1. action: Create, Update, Delete
     *  2. filter: Server guaranteed filter to locate target item, usually primary key.
     *  3. timestamp: An update timestamp for dedup and/or aggregation.
     */
    function onBayeuxMessage(message) {
      switch ( message.action ) {
        case 'create':
        case 'update':
          var modelName = String(message['@collection']).trim();

          if ( !modelName ) {
            console.info('No collection name to refresh, message: ', message);
            return;
          }

          if ( $timers[modelName] ) {
            $timeout.cancel($timers[modelName]);
            $timers[modelName] = null;
          }

          $timers[modelName] = $timeout(function() {
            $rootScope.$emit('$list:reload', modelName);
          }, 1000);
          break;

        case 'delete':
          /*! Note @ 22 Sep, 2015
           *  We don't have delete messages currently because WorkInstances do
           *  not actually deletes, they only closes.
           */
          break;
      }
    }

    // note: don't need to unsubscribe on $destroy because $rootScope.
  });

  // Model loading events
  $rootScope.$on('$list:load', function($evt, modelName) {
    loadCollection(modelName, $rs.filter[modelName], true);
  });

  $rootScope.$on('$list:reload', function($evt, modelName) {
    loadCollection(modelName, $rs.filter[modelName], false);
  });

  $rootScope.$on('$item:load', function($evt, modelName, filter, params) {
    var $collection = getCollectionName(modelName);

    var $rc = $rootScope[$collection] || [];

    // add reference to collection
    $rs.$collection = $rc;

    var $p = $rc.$promise;
    if ( !$p ) {
      var $d = $q.defer();
      $p = $d.promise;
      $d.resolve();
      $d = null;
    }

    $p.then(function() {
      var $model = $injector.get(modelName);

      if ( filter ) {
        $rs.currentItem = $filter('filter')($rc, filter).shift() || $model.get(angular.extend({}, filter, params));
      }
      else {
        $rs.currentItem = new $model();
      }

      var $item = $rs.currentItem;

      // downloading, listen for errors
      if ( !$item.$resolved && $item.$promise ) {
        $item.$promise.then(null, function() {
          $location.path('/').replace();
        });
      }

      if ( !$rs.schema || !$rs.form ) {
        $rs.schema = $model.dataSchema();
        $rs.form = $model.formSchema();

        $q.all([$rs.schema.$promise, $rs.form.$promise]).then(function() {
          $rootScope.$broadcast('schemaFormRedraw');
        });
      }
    });

    // remove item related properties
    $evt.targetScope.$on('$destroy', function() {
      delete $rs.$collection;
      delete $rs.currentItem;
      delete $rs.schema;
      delete $rs.form;
    });
  });

  function getCollectionName(modelName) {
    return String(modelName).toLowerCase() + 's';
  }

  function loadCollection(modelName, filter, lengthen) {
    var $model = $injector.get(modelName)
      , listName = getCollectionName(modelName)
      , loaderName = listName + 'Load';

    if ( !$model || $rs[loaderName] ) {
      return;
    }

    // initialize collection for the first time
    var $rc = $rootScope[listName];
    if ( !$rc ) {
      $rc = $rootScope[listName] = [];
      $rc.$unwatch = $rootScope.$watch('states.filter.' + modelName,
        function(filter) { loadCollection(modelName, filter, false); },
        true);
    }

    // clear list on reload calls
    if ( !lengthen ) {
      // $rc.splice(0, $rc.length);
      delete $rc.$finish;
    }

    // skip processing ASAP
    if ( $rc.$finish ) {
      return;
    }

    var $offset = lengthen && $rc.length || 0;

    // load collection with filter
    filter = angular.extend(filter || {},
      // default parameters
      { '@limits': [$offset, $rs.listThreshold[1]].join('-')
      });

    // remove empty string
    angular.forEach(filter, function(value, key, obj) {
      if ( value == '' ) {
        delete obj[key];
      }
    });

    // skip if filter didn't change
    if ( lengthen && $rc.$filter && angular.equals($rc.$filter, filter) ) {
      return;
    }

    // note: start collection loading
    $rs[loaderName] = true;
    $rc.$promise = $model.query(filter, function(collection) {
      if ( collection.length < $rs.listThreshold[1] ) {
        $rc.$finish = true;
      }

      $rc.splice.apply($rc, [$offset, lengthen ? 0 : $rc.length].concat(collection));
      $rc.$filter = angular.copy(filter);
    })
    .$promise.finally(function() {
      delete $rs[loaderName];
    });
  }
});

//------------------------------------------------------------------------------
//
//  Controllers
//
//------------------------------------------------------------------------------

/*! Login Controller
 *  Takes care of the login process.
 */
app.controller('LoginCtrl', function LoginCtrl($rootScope, $location, notify, $http, $cookies, $scope, User) {
  var s = $scope.states || {};

  s.title = 'Login';

  // prevent redirection loop
  if ( s.__prev == $location.path() ) {
    delete s.__prev;
  }

  // check if session user has been there
  // note: $watch should dispose itself upon $scope destroy.
  $scope.$watch('states.user', function(user) {
    if ( user && user.id ) {
      $location.path(s.__prev || '/').replace();
      delete s.__prev;
    }
  });

  $scope.doLogin = function() {
    s.formLock = true;

    $http.get('/service/sessions/validate/' +
      encodeURIComponent(this.username) + '/' +
      encodeURIComponent(this.password))
      .then(onLogin)
      .finally(function() {
        delete s.formLock;
      });
  };

  function onLogin(response) {
    notify.closeAll();

    // __sid returned, store it and back.
    if ( typeof response.data == 'string' ) {
      $cookies.put('__sid', response.data);
    }

    User.get({ id: '~' }, function(user) {
      s.user = user;
    });
  }
});

/*! Logout Controller
 *  Attempt to logout and retry on failure.
 */
app.controller('LogoutCtrl', function LogoutCtrl($http, $cookies, $scope, $location, $timeout, notify) {
  function doLogout() {
    if ( !$cookies.get('__sid') ) {
      onLogout();
    }
    else {
      $http.get('/service/sessions/invalidate/' + $cookies.get('__sid'))
        .then(onLogout, noLogout);
    }
  }
  doLogout();

  function onLogout() {
    var s = $scope.states;

    // reset everything in $rootScope
    delete s.user;

    s.filter = {};

    for ( var i in $scope.$root ) {
      if ( !/^\$/.test(i) && i != 'states' ) {
        delete $scope.$root[i];
      }
    }

    // remove cookie
    $cookies.remove('__sid');

    // redirect back to home
    $location.path('/login');
  }

  function noLogout() {
    notify({
        message: 'Retrying logout in 5 seconds...',
        classes: 'alert-info',
        duration: 2000
      });

    $timeout(doLogout, 1000);
  }
});

/*! Home Controller
 *  Lists available work flows to the current user, this includes create options
 *  and in-progress work flows.
 */
app.controller('HomeCtrl', function HomeCtrl($sce, $http, $scope, hotkeys, WorkInstance, parseTimestamp, parseNextTask) {
  var s = $scope.states || {};

  // page title
  s.title = 'Home';

  // default sorting
  if ( !s.filter.WorkInstance ) {
    s.filter.WorkInstance = { '@sorter': { timestamp: 0 } };
  }

  // note: house keeping
  $scope.$on('$destroy', function() {
    delete s.jobSchema;
    delete s.jobForm;
  });

  // note: load models
  $scope.$emit('$list:load', 'Work');
  $scope.$emit('$list:load', 'WorkInstance');

  $scope.lengthenWorks = function() {
    $scope.$emit('$list:load', 'Work');
  };

  $scope.lengthenInstances = function() {
    $scope.$emit('$list:load', 'WorkInstance');
  };

  $scope.reloadLists = function() {
    $scope.$emit('$list:reload', 'Work');
    $scope.$emit('$list:reload', 'WorkInstance');
  };

  // hotkeys
  hotkeys.bindTo($scope)
    .add({
      combo: 'mod+/',
      allowIn: ['input'],
      callback: function($evt) {
        $evt.preventDefault();
        angular.element('#txtJobSearch').focus();
      }
    });
});

/*! Task Controller
 *  Template to work with.
 */
app.controller('TaskCtrl', function TaskCtrl($q, $timeout, $location, $http, $scope, $rootScope, $routeParams, notify, hotkeys, parseNextTask) {
  var s = $scope.states || {}
    , $task;

  // Create instance from Work, render first Task view.
  if ( $routeParams.workId ) {
    s.title = 'New Task';

    // note: create template context from work
    $scope.$emit('$item:load', 'Work', { id: $routeParams.workId });

    $scope.description = '';

    $scope.doCreate = function() {
      var work = s.currentItem;

      if ( s.formLock ) {
        return;
      }

      s.formLock = true;

      var $req = $http.post('/service/_/Work/get/' + work.uuid + '?@output=invokes(createInstance)',
        { description: $scope.description });

      $req.then(function(response) {
        /*! note:
         *  Since we post the first task data when creating, nextTask could be
         *  invisible to the current user. Takes care of such response.
         */

        if ( response.status != 201 ) {
          // note; error, do nothing.
          return;
        }

        if ( !angular.isObject(response.data) ) {
          notify('Server responded with invalid data, please try again.');
          return;
        }

        var workInstance = response.data;

        if ( $scope.workinstances ) {
          $scope.workinstances.push(workInstance);
        }
        else {
          $scope.$emit('$list:reload', 'WorkInstance');
        }

        workInstance = parseNextTask(workInstance);

        // Immediately go to the first task
        if ( response.data.nextTask ) {
          $location.path('/task/' + workInstance.nextTask).replace();
        }
        else {
          $location.path('/').replace();
        }
      })
      .finally(function() {
        delete s.formLock;
      });
    };
  }
  // Render next Task view in a previously created WorkInstance.
  else {
    s.title = 'Task';

    // note: create template context from work instance
    $scope.$emit('$item:load', 'WorkInstance', { nextTask: $routeParams.uuid }, { '@output': 'unwraps()' });

    $scope.$watch('states.currentItem', function(instance) {
      if ( instance ) {
        (instance.$promise || $q.resolve()).then(function() {
          if ( instance.nextTask ) {
            s.baseUrl = '/task/' + instance.nextTask + '/';
          }

          // make a shortcut for $task object
          $scope.$task = instance.__nextTask;
          $scope.$store = instance.dataStore || {};
        });
      }
    }, true);

    $scope.$on('$destroy', function() {
      s.baseUrl = '/';
    });

    $scope.doProcess = function() {
      var url = '/service/_/WorkInstance/get/' + s.currentItem.uuid + '?@output=invokes(process)'
        , data = $scope.$store || {};

      $http.post(url, data)
        .then(function() {
          $scope.$emit('$list:reload', 'WorkInstance');

          $location.path('/');
        });
    };

    hotkeys.bindTo($scope)
      .add({
        combo: 'tab',
        allowIn: ['TEXTAREA'],
        callback: function($evt) {
          var node = $evt.target
            , sS = node.selectionStart
            , sE = node.selectionEnd;

          $evt.preventDefault();

          node.value = node.value.substr(0, sS) + '  ' + node.value.substr(sE);

          node.selectionStart = node.selectionEnd = sS + 2;
        }
      });
  }
});

/*! Account Controller
 */
app.controller('AccountCtrl', function($scope) {
  var s = $scope.states || {};

  s.title = 'Account';

  // todo: implement this
});

/*! Administrator Controller
 *  Lists all admin modules, such as user/role, work flow assignment, component permissions... etc.
 */
app.controller('AdminCtrl', function AdminCtrl($q, $timeout, $location, hotkeys, $scope, $route, $routeParams, $filter) {
  // View states
  var s = $scope.states;

  s.title = 'Control Panel';

  // Submit handler
  $scope.doSubmit = function($evt, schemaForm) {
    var $form = angular.element($evt.target);

    $evt.preventDefault();

    $scope.$broadcast('schemaFormValidate');

    if ( schemaForm.$valid ) {
      s.formLock = true;

      // note; hide modal dialogs if any one is active
      if ( $('.modal.in').length ) {
        $('.modal.in')
          .modal('hide')
          .on('hidden.bs.modal', _doSubmit);
      }
      else {
        _doSubmit();
      }
    }

    function _doSubmit() {
      delete s.currentItem.timestamp;

      // todo: should determined by HTTP status code after $save() instead, but we have no way to get that from $save().
      if ( s.$collection.indexOf(s.currentItem) == -1 ) {
        s.$collection.push(s.currentItem);
      }

      s.currentItem.$save(function() {
        $location.path($form.attr('action'));

        delete s.formLock;
      }, function() {
        delete s.formLock;
      });
    }
  };

  $scope.doDelete = function($evt) {
    var $form = angular.element($evt.target).closest('form');

    s.formLock = true;
    s.currentItem.$delete({ id: s.currentItem.uuid || s.currentItem.id }, function() {
      var index = s.$collection.indexOf(s.currentItem);
      if ( index > -1 ) {
        s.$collection.splice(index, 1);
      }

      $location.path($form.attr('action'));

      delete s.formLock;
      delete s.deleteLock;
    }, function() {
      delete s.formLock;
      delete s.deleteLock;
    });
  };

  var redrawTimeout;
  $scope.schemaFormRedraw = function() {
    if ( redrawTimeout ) {
      $timeout.cancel(redrawTimeout);
    }

    redrawTimeout = $timeout(function() {
      $scope.$broadcast('schemaFormRedraw');
      redrawTimeout = null;
    });
  };

  var path = $routeParams.path.split('/').shift()
    , itemPath = $routeParams.path.split(/\/(.*)$/)[1]
    ;

  // route resolve injection?
  switch ( path ) {
    case 'workflows':
      $scope.$emit('$list:load', 'Work');
      $scope.$emit('$list:load', 'Task');

      if ( itemPath ) {
        $scope.$emit('$item:load', 'Work', itemPath == 'new' ? null : { uuid: itemPath });

        $scope.$watchCollection('states.currentItem.tasks', function(tasks) {
          if ( angular.isArray(tasks) ) {
            angular.forEach(tasks, function(task) {
              if ( !angular.isObject(task.settings) ) {
                task.settings = {};
              }
            });
          }
        });

        $scope.addTask = function(task) {
          var _task = angular.extend(task.schema ? { settings: {} } : {}, task);

          if ( !s.currentItem.tasks ) {
            s.currentItem.tasks = [];
          }

          s.currentItem.tasks.push(_task);
        };

        $scope.removeTask = function(task) {
          var tasks = s.currentItem.tasks
            , index = tasks.indexOf(task);

          if ( index > -1 ) {
            tasks.splice(index, 1);
          }
        };
      }
      break;

    case 'tasks':
      $scope.$emit('$list:load', 'Task');

      if ( itemPath ) {
        $scope.$emit('$item:load', 'Task', itemPath == 'new' ? null : { name: itemPath });

        var $unwatch = $scope.$watch('states.currentItem', function($item) {
          if ( $item ) {
            $unwatch();
          }
          else {
            return;
          }

          var $p = $item.$promise || $q.resolve();

          $p.then(function() {
            return;

            if ( $item.schema ) {
              $item.schema = angular.toJson($item.schema, true);
              $item.hasSettings = true;

              // $scope.$watch('states.currentItem.schemaJson', function(value) {
              //   try {
              //     $item.schema = angular.fromJson(value);
              //   }
              //   catch (e) { /* does not compile */ }
              // });
            }

            if ( $item.form ) {
              $item.form = angular.toJson($item.form, true);
              $item.hasSettings = true;

              // $scope.$watch('states.currentItem.formJson', function(value) {
              //   try {
              //     $item.form = angular.fromJson(value);
              //   }
              //   catch (e) { /* does not compile */ }
              // });
            }

            $scope.$broadcast('schemaFormRedraw');
          });
        });
      }
      break;

    case 'users':
      $scope.$emit('$list:load', 'User');

      if ( itemPath ) {
        $scope.$emit('$item:load', 'User', itemPath == 'new' ? null : { username: itemPath });
      }
      break;
  }

  // reset view states
  $scope.$on('$routeChangeStart', function() {
    delete s.searchText;
    delete s.doDelete;
  });

  // house keeping
  $scope.$on('$destroy', function() {
    delete s.formLock;
  });

  // hotkeys
  hotkeys.bindTo($scope)
    .add({
      combo: 'mod+/',
      allowIn: ['input'],
      callback: function($evt) {
        $evt.preventDefault();
        angular.element('#txtSearch').focus();
      }
    });
});

})();
