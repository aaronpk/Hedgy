<?php
chdir(__DIR__.'/..');
require 'vendor/autoload.php';

$users = ORM::for_table('users')->find_many();
foreach($users as $user) {
  if($user->last_fetched == '' || strtotime($user->last_fetched) < time()-300) {
    DeferredTask::queue('HedgyTask', 'process_user', [$user->id]);
  }
}
