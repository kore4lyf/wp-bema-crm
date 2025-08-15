<?php
namespace Bema;

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

class Utils {

    /**
     * get_campaign_group_name: Generates campaign group name
     * 
     * @param int $albumReleaseYear The year the album was released.
     * @param string $album The album name.
     * @param string $artist The artist name.
     * @param string $tier The tier name the 
     * @return string
     */
    public function get_campaign_group_name( int|string $albumReleaseYear, string $artist, string $album, string $tier ):string {
      $artistShort = $this->get_name_short($artist);
      $albumShort = $this->get_name_short($album);
      $transformed_tier = $this->transform_tier($tier);

      return $albumReleaseYear . '_' . $artistShort . '_' . $albumShort . '_' . $transformed_tier;
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
     * Case 3: If artist name is empty return an empty value.ss
     * 
     * @param string $name Value may be an album name or an artist name
     * @return string
     */
    private function get_name_short( string $name ):string {

      $name = trim($name);

      if(empty($name)) {
          return '';
      }
      
      $name = strtoupper( $name );
      $name_name = explode( ' ', $name );
      $name_name_size = count( $name_name );

      if( $name_name_size === 1 ) {
          return $name;
      }

      if( $name_name_size > 1) {
          $name_short = '';

          foreach($name_name as $name) {
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
    private function transform_tier( string $tier ):string {
      $tier = trim($tier);

      if(empty($tier)) {
        return '';
      }

      $tier = strtoupper($tier);
      $tier = str_replace(' ', '_', $tier);

      return $tier;
    }
}