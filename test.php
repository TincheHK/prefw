<?php

// $res = (new models\WorkInstance)->find();
// $res = $res[0];
// $res->nextTask = $res->tasks[0]->uuid;
// $res->save();
// __halt_compiler();

// $res = (new models\WorkInstance)->find(array('state' => 'Closed'));
// $res = $res[0];
// $res->nextTask = $res->tasks[0]->uuid;
// $res->state = 'Open';
// $res->save();

__halt_compiler();

$res = array_map(invokes('delete'), (new models\WorkInstance)->find());

var_dump($res);

__halt_compiler();

$res = new \Nc\FayeClient\Adapter\CurlAdapter();

$client = new \Nc\FayeClient\Client($res, 'http://prefw.dev:8080/');

$res = $client->send('/feed/deveric@localhost', array(
  'action' => 'update',
  '@collection' => 'WorkInstance',
  'filter' => array(
    'id' => 2
  ),
  'timestamp' => '2015-09-16 12:17:01'

  // 'action' => 'logout',
  // 'username' => 'deveric@localhost'
));

__halt_compiler();

$uuid = core\Node::getOne('TaskInstance');
$uuid = unpack('H*', $uuid['uuid']);
$uuid = reset($uuid);

var_dump($uuid);

$taskInstance = new models\TaskInstance();
$res = $taskInstance->find(array(
  'uuid' => $uuid
  ));

var_dump($res);

$res = $taskInstance->load($uuid);

var_dump($res);

$uuid = pack('H*', $uuid);
$uuid = core\Node::getOne(array(
    '@collection' => 'TaskInstance',
    'uuid' => $uuid
  ));

var_dump($uuid['uuid']);
