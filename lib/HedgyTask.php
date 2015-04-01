<?php
use BarnabyWalters\Mf2;

class HedgyTask {

  private static function _is_alcohol($string) {
    if($string == '') return false;

    // check if the string matches known alcohol words

    $match = [
      'champagne',
      'mimosa',
      'wine',
      'prosecco',
      'cocktail',
      'beer',
      'margarita',
    ];
    foreach($match as $m) {
      if(stripos($string, $m) !== false)
        return true;
    }
    return false;
  }
  private static function _is_meal($string) {
    if($string == '') return false;
    // consider everything a meal except certain snacks

    $match = [

    ];
    foreach($match as $m) {
      if(stripos($string, $m) !== false)
        return false;
    }
    return true;
  }

  public static function process_user($user_id) {

    $entries = self::fetch_feed($user_id);

    // Add any new entries to the database
    foreach($entries as $e) {
      if(Mf2\hasProp($e, 'url')) {
        if(Mf2\hasProp($e, 'p3k-food') || Mf2\hasProp($e, 'p3k-drink')) {
          $published = Mf2\getDateTimeProperty('published', $e, true);
          if($published) {
            $published = date('Y-m-d H:i:s', strtotime($published));
          }

          $type = Mf2\hasProp($e, 'p3k-food') ? 'food' : 'drink';
          $drink = Mf2\getPlaintext($e, 'p3k-drink');
          $food = Mf2\getPlaintext($e, 'p3k-food');

          $entry = db\find_or_create('entries', [
            'user_id' => $user_id,
            'url' => Mf2\getPlaintext($e, 'url')
          ], [
            'published' => $published,
            'content' => Mf2\getPlaintext($e, 'content'),
            'food' => $food,
            'drink' => $drink,
            'type' => $type,
            'is_alcohol' => self::_is_alcohol($drink) ? 1 : 0,
            'is_meal' => self::_is_meal($food) ? 1 : 0
          ], true);
        }
      } else {
        echo "Skipping entry because it has no URL\n";
      }
    }

    $newest = ORM::for_table('entries')->where('user_id', $user_id)->where('processed', 0)->order_by_desc('published')->find_one();
    if($newest) {
      echo $newest->id."\n";
      $entry_id = $newest->id;
      // Find the newest entry and process it
      self::process_entry($entry_id);
    }
    $set = ORM::for_table('entries')->where('user_id', $user_id)->where('processed', 0)->find_result_set();
    $set->set('processed', 1);
    $set->save();
  }

  private static function fetch_feed($user_id) {
    // Fetch the feed over HTTP and check for new entries, comparing the entries in the feed to the ones we have in the DB
    try {
      $user = db\get_by_id('users', $user_id);
      if(!$user)
        return ['error' => 'User not found'];
      if(!$user->feed_url)
        return ['error' => 'No feed URL for this user'];

      $entries = \Mf2\fetch($user->feed_url);
      if($entries) {
        return Mf2\findMicroformatsByType($entries['items'], 'h-entry');
      } else {
        return ['error' => 'Unable to fetch the feed URL'];
      }

      return $entries;

    } catch(Exception $e) {
      return ['error' => 'Exception trying to parse feed'];
    }
  }

  private static function process_entry($entry_id) {
    // Check if the food/drink threshold is met

    // Post a reply via Micropub

  }

  private static function micropub_post() {

  }
  
}
