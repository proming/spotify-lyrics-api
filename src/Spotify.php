<?php
namespace SpotifyLyricsApi;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Encoding;

/**
* Class Spotify
*
* This class is responsible for interacting with the Spotify API.
*/

class Spotify {
    private $token_url = 'https://open.spotify.com/get_access_token';
    private $lyrics_url = 'https://spclient.wg.spotify.com/color-lyrics/v2/track/';
    private $server_time_url = 'https://open.spotify.com/server-time';
    private $client_token_url = 'https://clienttoken.spotify.com/v1/clienttoken';
    private $sp_dc;
    private $cache_file;

    /**
    * Spotify constructor.
    *
    * @param string $sp_dc The Spotify Data Controller ( sp_dc ) cookie value.
    */

    function __construct( $sp_dc ) {
        $this->cache_file = sys_get_temp_dir() . '/spotify_token.json';
        $this->sp_dc = $sp_dc;
    }

    /**
    * Loads the cache file and returns the data as an array.
    *
    * @return array The cache data.
    */

    private function loadCacheFile(): array {
        if ( file_exists( $this->cache_file ) ) {
            $json = file_get_contents( $this->cache_file );
            $data = json_decode( $json, true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }
        return [];
    }

    /**
    * Saves the cache data to the cache file.
    *
    * @param array $data The cache data to save.
    */

    private function saveCacheFile( array $data ): void {
        $token_file = fopen( $this->cache_file, 'w' ) or die( 'Unable to open file!' );
        fwrite( $token_file, json_encode( $data ) );
        fclose( $token_file );
    }

    /**
    * Generates a Time-based One-Time Password ( TOTP ) using the server time.
    *
    * @param int $server_time_seconds The server time in seconds.
    * @return string The generated TOTP code.
    */

    function generate_totp( $server_time_seconds ) {
        // Using the hardcoded secret from the Go code
        $secret_base32 = 'GU2TANZRGQ2TQNJTGQ4DONBZHE2TSMRSGQ4DMMZQGMZDSMZUG4';

        $totp = TOTP::create(
            $secret_base32,
            30,
            'sha1',
            6
        );

        return $totp->at( intval( $server_time_seconds ) );
    }

    /**
    * Retrieves the server time and returns the parameters needed for the token request.
    *
    * @return array The parameters for the token request and the server time.
    * @throws SpotifyException If there is an error fetching the server time.
    */

    function getServerTimeParams(): array {
        try {
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->server_time_url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'referer: https://open.spotify.com/',
                'origin: https://open.spotify.com/',
                'accept: application/json',
                'app-platform: WebPlayer',
                'spotify-app-version: 1.2.61.20.g3b4cd5b2',
                'user-agent: Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
                'cookie: sp_dc=' . $this->sp_dc
            ] );

            $server_time_result = curl_exec( $ch );
            if ( $server_time_result === false ) {
                throw new SpotifyException( 'Failed to fetch server time: ' . curl_error( $ch ) );
            }
            $server_time_data = json_decode( $server_time_result, true );
            if ( !$server_time_data || !isset( $server_time_data[ 'serverTime' ] ) ) {
                throw new SpotifyException( 'Invalid server time response' );
            }
            $server_time_seconds = $server_time_data[ 'serverTime' ];

            $totp = $this->generate_totp( $server_time_seconds );
            $time_str = strval( $server_time_seconds );

            $params = [
                'reason' => 'transport',
                'productType' => 'web-player',
                'totp' => $totp,
                'totpServer' => $totp,
                'totpVer' => '5',
                'sTime' => $time_str,
                'cTime' => $time_str . '420'
            ];

            return $params;
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Retrieves an access token from Spotify and stores it in a file.
    * The file is stored in the temporary directory.
    *
    * @throws SpotifyException If there is an error during the token request.
    */

    function getToken(): void {
        if ( !$this->sp_dc ) {
            throw new SpotifyException( 'Please set SP_DC as an environmental variable.' );
        }
        try {
            $params = $this->getServerTimeParams();
            $headers = [
                'referer: https://open.spotify.com/',
                'origin: https://open.spotify.com/',
                'accept: application/json',
                'app-platform: WebPlayer',
                'spotify-app-version: 1.2.61.20.g3b4cd5b2',
                'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
                'Cookie: sp_dc=' . $this->sp_dc
            ];
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $this->token_url . '?' . http_build_query( $params ) );
            curl_setopt( $ch, CURLOPT_TIMEOUT, 600 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
            curl_setopt( $ch, CURLOPT_VERBOSE, 0 );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_HEADER, 0 );
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

            $result = curl_exec( $ch );
            if ( $result === false ) {
                throw new SpotifyException( 'Token request failed: ' . curl_error( $ch ) );
            }
            $token_json = json_decode( $result, true );
            if ( !$token_json || ( isset( $token_json[ 'isAnonymous' ] ) && $token_json[ 'isAnonymous' ] ) ) {
                throw new SpotifyException( 'The SP_DC set seems to be invalid, please correct it!' );
            }

            $cache_data = $this->loadCacheFile();

            $cache_data[ 'accessToken' ] = $token_json[ 'accessToken' ];
            $cache_data[ 'clientId' ] = $token_json[ 'clientId' ] ?? ( $cache_data[ 'clientId' ] ?? null );
            $cache_data[ 'accessTokenExpirationTimestampMs' ] = $token_json[ 'accessTokenExpirationTimestampMs' ];

            $this->saveCacheFile( $cache_data );
        } catch ( Exception $e ) {
            throw new SpotifyException( $e->getMessage() );
        }
        finally {
            curl_close( $ch );
        }
    }

    /**
    * Checks if the access token and client token are expired and retrieves new ones if needed.
    * This function should be called before making any API requests that require tokens.
    */

    function checkTokensExpire(): void {
        $check = file_exists( $this->cache_file );
        $cache_data = [];

        if ( $check ) {
            $cache_data = $this->loadCacheFile();
        }

        $needAccessToken = !$check ||
        !isset( $cache_data[ 'accessToken' ] ) ||
        !isset( $cache_data[ 'accessTokenExpirationTimestampMs' ] ) ||
        $cache_data[ 'accessTokenExpirationTimestampMs' ] < round( microtime( true ) * 1000 );

        if ( $needAccessToken ) {
            $this->getToken();
        }
    }

    /**
    * Retrieves the lyrics of a track from the Spotify.
    * @param string $track_id The Spotify track id.
    * @return string The lyrics of the track in JSON format.
    */

    function getLyrics( $track_id ): string {
        $this->checkTokensExpire();
        $cache_data = $this->loadCacheFile();
        $token = $cache_data[ 'accessToken' ];
        $formated_url = $this->lyrics_url . $track_id . '?format=json&vocalRemoval=false&market=from_token';
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'referer: https://open.spotify.com/',
            'origin: https://open.spotify.com/',
            'accept: application/json',
            'app-platform: WebPlayer',
            'spotify-app-version: 1.2.61.20.g3b4cd5b2',
            "authorization: Bearer $token"
        ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_URL, $formated_url );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
        $result = curl_exec( $ch );
        curl_close( $ch );
        return $result;
    }

    /**
    * Retrieves the lyrics in LRC format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in LRC format.
    */

    function getLrcLyrics( $lyrics ): array {
        $lrc = array();
        foreach ( $lyrics as $lines ) {
            $lrctime = $this->formatMS( $lines[ 'startTimeMs' ] );
            array_push( $lrc, [ 'timeTag' => $lrctime, 'words' => $lines[ 'words' ] ] );
        }
        return $lrc;
    }

    /**
    * Retrieves the lyrics in SRT format.
    *
    * @param array $lyrics The lyrics data.
    * @return array The lyrics in SRT format.
    */

    function getSrtLyrics( $lyrics ): array {
        $srt = array();
        for ( $i = 1; $i < count( $lyrics );
        $i++ ) {
            $srttime = $this->formatSRT( $lyrics[ $i-1 ][ 'startTimeMs' ] );
            $srtendtime = $this->formatSRT( $lyrics[ $i ][ 'startTimeMs' ] );
            array_push( $srt, [ 'index' => $i, 'startTime' => $srttime, 'endTime' => $srtendtime, 'words' => $lyrics[ $i-1 ][ 'words' ] ] );
        }
        return $srt;
    }

    /**
    * Helper fucntion for getLrcLyrics to change miliseconds to [ mm:ss.xx ]
    * @param int $milliseconds The time in miliseconds.
    * @return string The time in [ mm:ss.xx ] format.
    */

    function formatMS( $milliseconds ): string {
        $th_secs = intdiv( $milliseconds, 1000 );
        $lrc_timetag = sprintf( '%02d:%02d.%02d', intdiv( $th_secs, 60 ), $th_secs % 60, intdiv( ( $milliseconds % 1000 ), 10 ) );
        return $lrc_timetag;
    }

    /**
    * Helper function to format milliseconds to SRT time format ( hh:mm:ss, ms ).
    * @param int $milliseconds The time in milliseconds.
    * @return string The time in SRT format.
    */

    function formatSRT( $milliseconds ): string {
        $hours = intdiv( $milliseconds, 3600000 );
        $minutes = intdiv( $milliseconds % 3600000, 60000 );
        $seconds = intdiv( $milliseconds % 60000, 1000 );
        $milliseconds = $milliseconds % 1000;
        return sprintf( '%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds );
    }
}
