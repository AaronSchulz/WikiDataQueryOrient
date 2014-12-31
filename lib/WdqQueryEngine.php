<?php

class WdqQueryEngine {
	/** @var MultiHttpClient */
	protected $http;

	/** @var string */
	protected $url;
	/** @var string */
	protected $user;
	/** @var string */
	protected $password;

	/** @var string */
	protected $sessionId;

	/**
	 * @param MultiHttpClient $http
	 * @param string $url
	 * @param string $user
	 * @param string $password
	 */
	public function __construct( MultiHttpClient $http, $url, $user, $password ) {
		$this->http = $http;
		$this->url = $url;
		$this->user = $user;
		$this->password = $password;
	}

	/**
	 * Issue a WDQ query and return the results
	 *
	 * @param string $query
	 * @param integer $timeout Seconds
	 * @param integer $limit Maximum record
	 * @return array
	 */
	public function query( $query, $timeout = 5000, $limit = 1e9 ) {
		$sql = WdqQueryParser::parse( $query, $timeout );

		$req = array(
			'method'  => 'GET',
			'url'     => "{$this->url}/query/WikiData/sql/" . rawurlencode( $sql ) . "/$limit",
			'headers' => array( 'Cookie' => "OSESSIONID={$this->getSessionId()}" )
		);
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );

		if ( $rcode == 401 ) {
			// Session probably expired; renew
			$this->sessionId = null;
			$req['headers']['Cookie'] = "OSESSIONID={$this->getSessionId()}";
			list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
		}

		$results = array();
		$response = json_decode( $rbody, true );
		if ( $response === null ) {
			throw new Exception( "HTTP error ($rcode): could not decode response ($rerr).\n\n" );
		} else {
			$count = 0;
			foreach ( $response['result'] as $record ) {
				++$count;
				$obj = array();
				foreach ( $record as $key => $value ) {
					if ( $key === '*depth' ) {
						$obj[$key] = $value / 2; // only count vertex steps
					} elseif ( $key === '*timevalue' ) {
						$obj['*value'] = WdqUtils::getISO8601FromUnixTime( $value );
					} elseif ( $key[0] !== '@' ) {
						$obj[$key] = $value;
					}
				}
				$results[] = $obj;
			}
		}

		return $results;
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	protected function getSessionId() {
		if ( $this->sessionId !== null ) {
			return $this->sessionId;
		}
		$hash = base64_encode( "{$this->user}:{$this->password}" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( array(
			'method'  => 'GET',
			'url'     => "{$this->url}/connect/WikiData",
			'headers' => array( 'Authorization' => "Basic " . $hash )
		) );
		$m = array();
		$ok = isset( $rhdrs['set-cookie'] );
		if ( $ok && preg_match( '/(?:^|;)OSESSIONID=([^;]+);/', $rhdrs['set-cookie'], $m ) ) {
			$this->sessionId = $m[1];
		} else {
			throw new Exception( "Invalid authorization credentials ($rcode).\n" );
		}

		return $this->sessionId;
	}
}
