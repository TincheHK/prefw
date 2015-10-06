<?php
/*! gateway.php | Starting point of all URI access. */

/***********************************************************************\
**                                                                     **
**             DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE             **
**                      Version 2, December 2004                       **
**                                                                     **
** Copyright (C) 2008 Vicary Archangel <vicary@victopia.org>           **
**                                                                     **
** Everyone is permitted to copy and distribute verbatim or modified   **
** copies of this license document, and changing it is allowed as long **
** as the name is changed.                                             **
**                                                                     **
**             DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE             **
**   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION   **
**                                                                     **
**  0. You just DO WHAT THE FUCK YOU WANT TO.                          **
**                                                                     **
\************************************************************************/

use framework\Configuration as conf;
use framework\System;

//--------------------------------------------------
//
//  Initialization
//
//--------------------------------------------------

require_once('scripts/Initialize.php');

//--------------------------------------------------
//
//  Resolve the request
//
//--------------------------------------------------

// Resolver chain
  $resolver = new framework\Resolver();

  // Maintenance resolver
    // Simply don't put it into chain when disabled.
    if ( conf::get('system::maintenance.enable') ) {
      $resolver->registerResolver(new resolvers\MaintenanceResolver(array(
          'templatePath' => conf::get('system::maintenance.templatePath'),
          'whitelist' => (array) conf::get('system::maintenance.whitelist')
        )), 999);
    }

  // Session authentication
    $resolver->registerResolver(new resolvers\UserContextResolver(array(
        // This enables the "startup" super user when no user in database.
        'setup' => System::environment() != System::ENV_PRODUCTION
      )), 100);

  // Access rules and policy
    $resolver->registerResolver(new resolvers\AuthenticationResolver(array(
        'paths' => conf::get('web::auth.paths'),
        'statusCode' => conf::get('web::auth.statusCode')
      )), 80);

  // Locale
    $resolver->registerResolver(new resolvers\LocaleResolver(array(
        'default' => conf::get('system::i18n.localeDefault', 'en_US')
      )), 70);

  // JSONP Headers
    $resolver->registerResolver(new resolvers\JsonpResolver(array(
        'defaultCallback' => conf::get('web::resolvers.jsonp.defaultCallback', 'callback')
      )), 65);

  // URI Rewrite
    $resolver->registerResolver(new resolvers\UriRewriteResolver(array(
        // note; redirect task requests
        array(
            'source' => function($uri) {
              return preg_match('/^\/task\/\w{32,32}\//', $uri);
            },
            'target' => function($request, $response) {
              // note; This pattern does not work: '/^\/task\/(\w{32,32})(?:\/(\w+)\/(\w+))?$/',
              //       PHP fails to match "/task/:hash/foo" with the last optional group.
              preg_match('/^\/task\/(\w{32,32})\/(.+)?$/', $request->uri('path'), $matches);

              // note; search task by UUID
              $instance = (new models\WorkInstance)->loadByNextTask($matches[1]);
              if ( empty($instance->nextTask) ) {
                return;
              }

              $instance = $instance->nextTask();

              // controller-action pattern
              if ( @$matches[2] ) {
                $fragments = array_values(array_filter(array_map('trim', explode('/', $matches[2]))));
                if ( !pathinfo($matches[2], PATHINFO_EXTENSION) && count($fragments) == 2 ) {
                  // note; if namespace collides with vendor classes, let it throw.
                  $res = ucfirst($fragments[0]) . 'Controller';
                  $taskdir = ".private/modules/$instance->name";

                  require_once("$taskdir/controllers/$res.php");
                  if ( class_exists($res) ) {
                    $res = array(new $res($instance), lcfirst($fragments[1]));
                    if ( is_callable($res) ) {
                      $cwd = getcwd();
                      chdir($taskdir); unset($taskdir);

                      // note; defaults status code to 200.
                      $response->status(200);

                      try {
                        call_user_func($res);
                      }
                      catch(\Exception $e) {
                        chdir($cwd); unset($cwd); // restore working directory before throwing
                        throw $e;
                      }

                      chdir($cwd); unset($cwd); // restore working directory

                      return; // then exits.
                    }
                  }

                  unset($res);
                }
                unset($fragments);
              }

              // static view assets redirection
              // note; composer.json demands the name to be of format "vendor/name".
              return @".private/modules/$instance->name/views/$matches[2]";
            }
          ),
        // note; redirect "/faye/client.js" to ":8080/client.js"
        array(
            'source' => '/^\/faye\/client.js/',
            'target' => array(
                'uri' => array(
                    'port' => 8080,
                    'path' => '/client.js',
                  ),
                'options' => array(
                    'status' => 307
                  ),
              ),
          ),
        // note; redirect all requests to root except "/assets" and "/service"
        array(
            'source' => funcAnd(matches('/^(?!(?:\/assets|\/service))/'), compose('not', pushesArg('pathinfo', PATHINFO_EXTENSION))),
            'target' => '/'
          ),
      )), 65);

  // Web Services
    $resolver->registerResolver(new resolvers\WebServiceResolver(array(
        'prefix' => conf::get('web::resolvers.service.prefix', '/service')
      )), 60);

  // Post Processers
    $resolver->registerResolver(new resolvers\InvokerPostProcessor(array(
        'invokes' => 'invokes',
        'unwraps' => 'core\Utility::unwrapAssoc'
      )), 50);

  // Template resolver
    // $templateResolver = new resolvers\TemplateResolver(array(
    //     'render' => function($path) {
    //         static $mustache;
    //         if ( !$mustache ) {
    //           $mustache = new Mustache_Engine();
    //         }

    //         $resource = util::getResourceContext();

    //         return $mustache->render(file_get_contents($path), $resource);
    //       }
    //   , 'extensions' => 'mustache html'
    //   ));

    // $templateResolver->directoryIndex('Home index');

    // $resolver->registerResolver($templateResolver, 50);

    // unset($templateResolver);

  // External URL
    $resolver->registerResolver(new resolvers\ExternalResolver(array(
        'source' => conf::get('system::paths.external.src')
      )), 40);

  // Markdown handling
    $resolver->registerResolver(new resolvers\MarkdownResolver(), 30);

  // SCSS Compiler
    $resolver->registerResolver(new resolvers\ScssResolver(array(
        'source' => conf::get('system::paths.scss.src'),
        'output' => conf::get('system::paths.scss.dst', 'assets/css')
      )), 30);

  // LESS Compiler
    $resolver->registerResolver(new resolvers\LessResolver(array(
        'source' => conf::get('system::paths.less.src'),
        'output' => conf::get('system::paths.less.dst', 'assets/css')
      )), 30);

  // Css Minifier
    $resolver->registerResolver(new resolvers\CssMinResolver(array(
        'source' => conf::get('system::paths.cssmin.src'),
        'output' => conf::get('system::paths.cssmin.dst', 'assets/css')
      )), 20);

  // Js Minifier
    $resolver->registerResolver(new resolvers\JsMinResolver(array(
        'source' => conf::get('system::paths.jsmin.src'),
        'output' => conf::get('system::paths.jsmin.dst', 'assets/js')
      )), 20);

  // Physical file handling
    $fileResolver = array(
        'directoryIndex' => conf::get('web::resolvers.file.indexes', 'Home index')
      );

    if ( conf::get('web::http.output.buffer.enable') ) {
      $fileResolver['outputBuffer']['size'] = conf::get('web::http.output.buffer.size', 1024);
    }

    $fileResolver = new resolvers\FileResolver($fileResolver);

    $resolver->registerResolver($fileResolver, 10);

    unset($fileResolver);

  // HTTP error pages in HTML or JSON
    $resolver->registerResolver(new resolvers\StatusDocumentResolver(array(
        'prefix' => conf::get('web::resolvers.errordocs.directory')
      )), 5);

  // Logging
    $resolver->registerResolver(new resolvers\LogResolver(), 0);

// Request context
  // We have no request context options currently, use defaults.

// Response context
  if ( conf::get('web::http.output.buffer.enable') ) {
    $response = new framework\Response(array(
        'outputBuffer' => array(
          'size' => conf::get('web::http.output.buffer.size', 1024)
        )
      ));
  }

// Start the application
  $resolver->run(@$request, @$response);
