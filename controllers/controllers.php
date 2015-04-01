<?php
use BarnabyWalters\Mf2;

function require_login(&$app) {
  if($user=current_user())
    return $user;

  $app->redirect('/');
  return false;
}

function require_login_json(&$app) {
  if($user=current_user())
    return $user;

  json_response($app, array('error'=>'not_logged_in'));
  return false;
}

function current_user() {
  if(!is_logged_in()) {
    return false;
  } else {
    return ORM::for_table('users')->find_one($_SESSION['user_id']);
  }
}

function is_logged_in() {
  return array_key_exists('user_id', $_SESSION);
}

function user_id() {
  if(is_logged_in()) {
    return $_SESSION['user_id'];
  } else {
    return false;
  }
}

$app->get('/', function() use($app) {
  $app->redirect(Config::$home_url);
});

$app->get('/login', function() use($app) {
  $params = $app->request()->params();

  $_SESSION['state'] = rand(100000,999999);

  $auth = [
    'me' => $params['me'],
    'client_id' => Config::$home_url,
    'redirect_uri' => Config::$base_url . '/indieauth',
    'response_type' => 'id',
    'state' => $_SESSION['state']
  ];

  $app->redirect(Config::$indieAuthServer . '?' . http_build_query($auth));
});

$app->get('/indieauth', function() use($app) {
  $params = $app->request()->params();

  if(!k($_SESSION, 'state'))
    return $app->redirect(Config::$home_url . '?error=missing_state');

  if(k($params, 'state') != $_SESSION['state'])
    return $app->redirect(Config::$home_url . '?error=state_mismatch');

  $result = request\post(Config::$indieAuthServer, [
    'me' => $params['me'],
    'state' => $_SESSION['state'],
    'code' => $params['code'],
    'redirect_uri' => Config::$base_url . '/indieauth'
  ]);
  if($result && k($result, 'body')) {
    parse_str($result['body'], $token);
    if($token && k($token, 'me')) {

      $me = normalize_url($token['me']);
      if($me) {

        $user = ORM::for_table('users')->where('url', $me)->find_one();
        if(!$user) {
          $user = ORM::for_table('users')->create();
          $user->url = $me;
          $user->date_created = db\now();
        }

        // fetch their home page and look for an h-card to get their name
        try {
          $homepage = \Mf2\fetch($me);
          if($homepage && is_array($homepage)) {
            $hcard = Mf2\findMicroformatsByType($homepage['items'], 'h-card', false);
            if($hcard && is_array($hcard)) {
              $author = $hcard[0];
              if($name = Mf2\getPlaintext($author, 'name')) {
                $user->name = $name;
              }
            }
          }
        } catch(Exception $e) {
          // ignore errors
        }

        $user->last_login = db\now();
        $user->save();

        $_SESSION['user_id'] = $user->id;
        return $app->redirect('/configure');
      }

    }
  }

  $app->redirect(Config::$home_url . '?error=unknown');
});

$app->get('/configure', function() use($app){
  if($user=require_login($app)) {

    $html = render('configure', array(
      'title' => 'Set Up Hedgy',
      'user' => $user
    ));
    $app->response()->body($html);
  }
});

$app->post('/configure/save', function() use($app) {
  if($user=require_login_json($app)) {
    $params = $app->request()->params();

    $user->feed_url = $params['url'];
    db\set_updated($user);
    $user->save();

    return json_response($app, [
      'result' => 'ok'
    ]);
  }
});

