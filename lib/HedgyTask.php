<?php

class HedgyTask {

  public static function process_user($user_id) {

    $hentrys = self::fetch_feed($user_id);

    // Add any new entries to the database
    foreach($hentrys as $e) {

    }

    // Find the newest entry and process it
    self::process_entry($entry_id);
  }

  private static function fetch_feed($user_id) {
    // Fetch the feed over HTTP and check for new entries, comparing the entries in the feed to the ones we have in the DB

  }

  private static function process_entry($entry_id) {
    // Check if the food/drink threshold is met

    // Post a reply via Micropub
    
  }

  private static function micropub_post() {
    
  }
  
}
