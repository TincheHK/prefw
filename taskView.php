<?php
/*! taskView.php | Render target task view. */

use models\Task;
use models\TaskInstance;

$pathParams = $this->request()->uri('path');
$pathParams = preg_replace('/^\/?taskView(.php)?/', '', $pathParams);
$pathParams = explode('/', $pathParams);

// note; url formats: /taskView?t=:id or /taskView?t=:uuid

$task = $this->request()->param('t');

// note; Task
if ( is_numeric($task) ) {
  $task = (new Task)->load($task);
}
// note; TaskInstance
else {
  $task = (new TaskInstance)->load($task);
}

if ( !$task->identity() ) {
  return $this->response()->status(404);
}

// note;security; Make sure current user has permission on view.
if ( !array_intersect((array) @$this->request()->user->groups, $task->userGroups()) ) {
  return $this->response()->status(401);
}

call_user_func(
  create_function('$this, $request, $response', $task->renderScript),
  $task, $this->request(), $this->response());
