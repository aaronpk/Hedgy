<?php
use BarnabyWalters\Mf2;

class HedgyTask {

  public function perform() {
    self::process_user($this->args['user_id']);
  }

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
      'cider',
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

    if(k($entries, 'error')) {
      echo "Error: " . $entries['error'] . "\n";
      return;
    }

    // Add any new entries to the database
    foreach($entries as $e) {
      if(Mf2\hasProp($e, 'url')) {
        if(Mf2\hasProp($e, 'p3k-food') || Mf2\hasProp($e, 'p3k-drink')) {
          $published = Mf2\getDateTimeProperty('published', $e, true);
          $published_offset = 0;
          if($published) {
            $date = new DateTime($published);
            $utc_date = date('Y-m-d H:i:s', strtotime($published));
          } else {
            $date = new DateTime();
          }

          $type = Mf2\hasProp($e, 'p3k-food') ? 'food' : 'drink';
          $drink = Mf2\getPlaintext($e, 'p3k-drink');
          $food = Mf2\getPlaintext($e, 'p3k-food');

          $entry = db\find_or_create('entries', [
            'user_id' => $user_id,
            'url' => Mf2\getPlaintext($e, 'url')
          ], [
            'published' => $utc_date,
            'published_offset' => $date->getOffset(),
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

    // Find the newest alcoholic drink and process it
    $newest = ORM::for_table('entries')
      ->where('user_id', $user_id)
      ->where('is_alcohol', 1)
      ->where('processed', 0)
      ->order_by_desc('published')
      ->find_one();
    if($newest) {
      echo $newest->id."\n";
      $entry_id = $newest->id;
      self::process_entry($entry_id);
    }
    $set = ORM::for_table('entries')->where('user_id', $user_id)->where('processed', 0)->find_result_set();
    $set->set('processed', 1);
    $set->save();

    $user = db\get_by_id('users', $user_id);
    $user->last_fetched = db\now();
    $user->save();
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

  public static function process_entry($entry_id) {
    // Check if the food/drink threshold is met for a particular entry

    $drink = db\get_by_id('entries', $entry_id);
    $user = db\get_by_id('users', $drink->user_id);

    // Load the last 30 posts
    $entries = ORM::for_table('entries')
      ->where('user_id', $user->id)
      ->where_lte('published', $drink->published)
      ->order_by_desc('published')
      ->limit(30)
      ->find_many();

    // In reverse chronological order, check the drinks to see if there are three drinks in under 1.5 hrs (45 minutes per drink).
    // If this matches, this takes priority over checking for food posts, and replies immediately

    $latest_drink = false;
    $third_drink = false;
    $drinks_found = 0;
    foreach($entries as $entry) {
      if($entry->is_alcohol) {
        $drinks_found++;
        if($drinks_found == 1)
          $latest_drink = $entry;
        if($drinks_found == 3)
          $third_drink = $entry;
      }
    }
    if($latest_drink && $third_drink) {
      // drink 1 at t=0, drink 2 at t=45, drink 3 at t=90
      $threshold = 60 * 60 * 1.5; // 1.5 hours

      $diff_seconds = strtotime($latest_drink->published) - strtotime($third_drink->published);
      if($diff_seconds < $threshold) {
        echo "Latest drink: " . $latest_drink->published . "\n";
        echo "Third drink: " . $third_drink->published . "\n";

        $replies = [];
        $replies[] = 'Three drinks in ' . round($diff_seconds / 60) . ' minutes! Slow down!';
        $replies[] = 'That last ' . strtolower(self::remove_article($drink->drink)) . ' should keep you going a while, try not to have another drink for a while!';

        $sentence = '@' . friendly_url($user->url) . ' ' . self::choose($replies);
        echo "posting: '$sentence'\n";
        self::post_reply($user, $drink, $sentence);

        return;
      }
    }


    // In reverse chronological order, count the number of alcoholic drinks until a food post is encountered
    $last_food = false;
    $num_drinks = 0;

    foreach($entries as $entry) {
      if($entry->is_alcohol)
        $num_drinks++;

      if($entry->is_meal) {
        $last_food = $entry;
        break;
      }
    }

    // If more than 2 drinks since food, and the food was more than a while ago, reply
    if($last_food && $num_drinks > 2) {
      echo "There were $num_drinks drinks since the last food post\n";
      echo "The last food post was at " . $last_food->published . "\n";
      echo "The last drink was at " . $drink->published . "\n";
      $diff_seconds = strtotime($drink->published) - strtotime($last_food->published);
      $rt = new \RelativeTime\RelativeTime();
      $relative_date = $rt->convert($drink->published, $last_food->published);
      echo "Last food was " . $diff_seconds . " seconds since the latest drink\n";
      echo $relative_date . "\n";

      $threshold = 3600 * 2;

      if(strtotime($last_food->published) < strtotime($drink->published) - $threshold) {
        echo "Sending a reply!\n";

        $replies = [];
        $replies[] = 'I\'m sure that\'s a tasty ' . strtolower(self::remove_article($drink->drink)) . ' but it\'s been a while since you last ate, you should get some food!';
        $replies[] = 'It\'s been a while since you last ate, might want to get some food to go with that ' . strtolower(self::remove_article($drink->drink)) . '!';
        $replies[] = 'You last ate ' . strtolower(self::remove_article($last_food->food)) . ' ' . $relative_date . ', might want to get some more food!';

        if($user->name) {
          $replies[] = 'Hey ' . $user->name . ', you\'ve had a few drinks since you last ate!';
        }

        // Force this reply on the 4th drink
        if($num_drinks == 4)
          $replies = ['Four drinks since your last meal! You should definitely get some food!'];

        $sentence = '@' . friendly_url($user->url) . ' ' . self::choose($replies);
        echo "posting: '$sentence'\n";
        self::post_reply($user, $drink, $sentence);
      }

    }
  }

  private static function post_reply(&$user, &$drink, $sentence) {
    $tz_offset = $drink->published_offset;
    $date = new DateTime();
    if($tz_offset > 0)
      $date->add(new DateInterval('PT'.$tz_offset.'S'));
    elseif($tz_offset < 0)
      $date->sub(new DateInterval('PT'.abs($tz_offset).'S'));
    $tz = ($tz_offset < 0 ? '-' : '+') . sprintf('%02d:%02d', abs($tz_offset/60/60), ($tz_offset/60)%60);
    $published = $date->format('Y-m-d\TH:i:s') . $tz;

    $post = self::micropub_post([
      'published' => $published,
      'in-reply-to' => $drink->url,
      'content' => $sentence
    ]);
    $location = $post['location'];
    $reply = db\create('replies');
    $reply->user_id = $user->id;
    $reply->in_reply_to_id = $drink->id;
    $reply->content = $sentence;
    $reply->url = $location;
    $reply->published = db\now();
    $reply->date_created = db\now();
    $reply->save();
  }

  private static function choose($arr) {
    return $arr[array_rand($arr)];
  }

  private static function remove_article($str) {
    return preg_replace('/^an? /', '', $str);
  }

  private static function micropub_post($params) {
    $ch = curl_init(Config::$micropub_server);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Authorization: Bearer ' . Config::$micropub_access_token
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['h'=>'entry'], $params)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = trim(substr($response, 0, $header_size));
    $location = false;
    if(preg_match('/Location: (.+)/', $headers, $match)) {
      $location = $match[1];
    }
    return [
      'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
      'headers' => $headers,
      'body' => substr($response, $header_size),
      'location' => $location
    ];
  }
  
}
