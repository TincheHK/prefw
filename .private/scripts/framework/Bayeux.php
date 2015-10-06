<?php
/*! Bayeux.php | Sends a message through faye channel. */

namespace framework;

class Bayeux {

  private static $client;

  private static function getClient() {
    $client = &self::$client;
    if ( !$client ) {
      $client = new \Nc\FayeClient\Adapter\CurlAdapter();
      $client = new \Nc\FayeClient\Client($client, System::getHostname('faye'));
    }

    return $client;
  }

  static function sendMessage($channel, $message, $extra = array()) {
    return static::getClient()->send($channel, $message, $extra);
  }

}
