<?php
namespace Bema;

if (!defined('ABSPATH')) {
  exit;
}

class Utils
{

  /**
   * get_campaign_group_name: Generates campaign group name
   * 
   * @param int $albumReleaseYear The year the album was released.
   * @param string $album The album name.
   * @param string $artist The artist name.
   * @param string $tier The tier name the 
   * @return string
   */
  public function get_campaign_group_name(int|string $albumReleaseYear, string $artist, string $album, ?string $tier = ''): string
  {
    $artistShort = $this->get_name_short($artist);
    $albumShort = $this->get_name_short($album);

    if ($tier) {
      $transformed_tier = $this->transform_tier($tier);
      return $albumReleaseYear . '_' . $artistShort . '_' . $albumShort . '_' . $transformed_tier;
    } else {
      return $albumReleaseYear . '_' . $artistShort . '_' . $albumShort;
    }
  }

  /**
   * get_name_short: Capitalizes and shortens a name (Logic is applicable to both artist name and album name).
   * 
   * Example: Using artist name
   * 
   * Case 1: Artists with a single name.
   * For example: Divine
   * result: DIVINE
   * 
   * Case 2: For artist with more that one name
   * For example: Eko the beat
   * result: EKB
   * 
   * Case 3: If artist name is empty return an empty value.
   * 
   * @param string $name Value may be an album name or an artist name
   * @return string
   */
  private function get_name_short(string $name): string
  {

    $name = trim($name);

    if (empty($name)) {
      return '';
    }

    $name = strtoupper($name);
    $name_name = explode(' ', $name);
    $name_name_size = count($name_name);

    if ($name_name_size === 1) {
      return $name;
    }

    if ($name_name_size > 1) {
      $name_short = '';

      foreach ($name_name as $name) {
        $name_short .= $name[0];
      }

      return $name_short;
    }
    return '';
  }

  /**
   * transform_tier: Capitalizes and shortens a tier.
   * 
   * tier example include: bronze, silver purchase e.t.c
   * 
   * The transfromation: 
   * input: bronze
   * result: BRONZE 
   * 
   * input: silver purchase
   * result: SILVER_PURCHASE
   * 
   * @param string $tier tier name
   * @return string
   */
  private function transform_tier(string $tier): string
  {
    $tier = trim($tier);

    if (empty($tier)) {
      return '';
    }

    $tier = strtoupper($tier);
    $tier = str_replace(' ', '_', $tier);

    return $tier;
  }


  /**
   * Retrieves all albums from the database, along with their artist, release year, and product ID.
   *
   * @return array{0: array{id: int, album: string, artist: string, year: string}} An array of album details.
   */
  public function get_all_albums(): array
  {
    global $wpdb;

    // Use a single query to retrieve all downloads (albums) and their associated artists.
    // This is more efficient than looping and running separate queries for each album.
    $query = $wpdb->prepare(
      "
        SELECT
            p.ID AS product_id,
            p.post_title AS album_name,
            p.post_date AS release_date,
            t.name AS artist_name
        FROM {$wpdb->posts} AS p
        INNER JOIN {$wpdb->term_relationships} AS tr
            ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} AS tt
            ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->terms} AS t
            ON tt.term_id = t.term_id
        WHERE
            p.post_type = 'download'
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'download_category'
            AND t.slug LIKE %s
        ORDER BY
            p.post_date DESC
        ",
      '%-artist'
    );

    $albums = $wpdb->get_results($query, ARRAY_A);

    if (empty($albums)) {
      return [];
    }

    $all_albums = [];

    // Loop through the results and format the data.
    foreach ($albums as $album) {
      $release_year = '0';
      if (!empty($album['release_date'])) {
        $dateArr = explode('-', $album['release_date']);
        $release_year = $dateArr[0];
      }

      $all_albums[] = [
        'product_id' => (int) $album['product_id'],
        'album' => $album['album_name'],
        'campaign' => $this->get_campaign_group_name($release_year, $album['artist_name'], $album['album_name']),
        'artist' => $album['artist_name'],
        'year' => $release_year,
      ];
    }

    return $all_albums;
  }


  public function get_campaigns_names(): array
  {
    $all_albums = $this->get_all_albums();
    $campaigns = [];
    foreach ($all_albums as $album) {
      $campaigns[] = $this->get_campaign_group_name($album['year'], $album['artist'], $album['album']);
    }

    return $campaigns;
  }

  public function get_campaign_group_names($campaign_name): array
  {
    $tiers = get_option('bema_crm_tiers', []);

    $campaign_group_names = [];

    foreach ($tiers as $tier) {
      $campaign_group_names[] = $campaign_name . '_' . $this->transform_tier($tier);
    }

    return $campaign_group_names;
  }

  public function get_tier_from_group_name($group_name)
  {
    $group_name_list = explode('_', $group_name);
    $group_name_size = count($group_name_list);

    $tier = '';

    for ($i = 3; $i < $group_name_size; $i++) {
      $tier .= $group_name_list[$i] . ' ';
    }

    $tier = trim($tier);

    return $tier;
  }

  public function get_campaign_name_from_text($text)
  {
    $text_list = explode('_', $text);
    $text_size = count($text_list);

    if ($text_size < 3) {
      return '';
    }

    return $text_list[0] . '_' . $text_list[1] . '_' . $text_list[2];
  }


  /**
   * get_album_details: returns album release year and artist name from a provided album name.
   * * @param string $album_name
   * @return array{artist:string, year: string}
   */
  public function get_album_details(string $album_name): array
  {
    global $wpdb;

    $album_name = sanitize_text_field($album_name);

    // Find the EDD download (post) by its title
    $query = $wpdb->prepare(
      "
        SELECT ID, post_date
        FROM {$wpdb->posts}
        WHERE post_title = %s
        AND post_type = 'download'
        AND post_status = 'publish'
        LIMIT 1
      ",
      $album_name
    );

    $album = $wpdb->get_row($query, ARRAY_A);

    if (empty($album)) {
      return [
        'product_id' => 0,
        'artist' => '',
        'year' => '0',
      ];
    }

    $product_id = (int) $album['ID'];
    $release_year = '0';
    if (!empty($album['post_date'])) {
      $dateArr = explode('-', $album['post_date']);
      $release_year = $dateArr[0];
    }

    $artist_name = '';

    // Fetch the artist name from the post's categories
    $artist_term = $wpdb->get_row(
      $wpdb->prepare(
        "
            SELECT t.name
            FROM {$wpdb->terms} AS t
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id = %d
            AND tt.taxonomy = 'download_category'
            AND t.slug LIKE %s
            LIMIT 1
            ",
        $album['ID'],
        '%-artist' // Search for a term slug ending in '-artist'
      ),
      ARRAY_A
    );

    if (!empty($artist_term['name'])) {
      $artist_name = $artist_term['name'];
    }

    return [
      'product_id' => $product_id,
      'artist' => $artist_name,
      'year' => $release_year,
    ];
  }

  /**
   * Merges multiple associative arrays into a single array, using a specific key
   * from each array to group them.
   *
   * @param string $mergeKey The key to use for merging and grouping the arrays.
   * @param array ...$arrays The associative arrays to be merged.
   * @return array A new associative array where keys are the values of the mergeKey,
   * and the values are the merged arrays.
   */
  function merge_arrays_by_key(string $mergeKey, array ...$arrays): array
  {
    $merged = [];
    foreach ($arrays as $array) {
      if (!is_array($array)) {
        // Skip non-array inputs.
        continue;
      }

      foreach ($array as $item) {
        if (!is_array($item) || !isset($item[$mergeKey])) {
          // Skip items that are not arrays or don't have the merge key.
          continue;
        }

        $key = $item[$mergeKey];
        if (!isset($merged[$key])) {
          $merged[$key] = $item;
        } else {
          $merged[$key] = array_merge($merged[$key], $item);
        }
      }
    }
    return $merged;
  }
}