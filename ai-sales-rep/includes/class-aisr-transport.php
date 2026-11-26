<?php
/**
 * PSR-18 HTTP client on top of the WordPress HTTP API, registered with php-http/discovery
 * so the Anthropic SDK uses it. No Guzzle needed, and WordPress proxy settings apply.
 */

defined( 'ABSPATH' ) || exit;

if ( file_exists( AISR_DIR . 'vendor/autoload.php' ) ) {
	require_once AISR_DIR . 'vendor/autoload.php';
}

if ( interface_exists( \Psr\Http\Client\ClientInterface::class ) ) {

	final class AISR_WP_Http_Client implements \Psr\Http\Client\ClientInterface {

		public function sendRequest( \Psr\Http\Message\RequestInterface $request ): \Psr\Http\Message\ResponseInterface {
			$headers = array();
			foreach ( $request->getHeaders() as $name => $values ) {
				$headers[ $name ] = implode( ', ', $values );
			}
			$result = wp_remote_request(
				(string) $request->getUri(),
				array(
					'method'      => $request->getMethod(),
					'headers'     => $headers,
					'body'        => (string) $request->getBody(),
					'timeout'     => 60,
					'redirection' => 0,
				)
			);
			if ( is_wp_error( $result ) ) {
				throw new AISR_Network_Exception( $result->get_error_message(), $request );
			}
			$response = new \Nyholm\Psr7\Response(
				(int) wp_remote_retrieve_response_code( $result ),
				array(),
				(string) wp_remote_retrieve_body( $result )
			);
			foreach ( wp_remote_retrieve_headers( $result ) as $name => $value ) {
				$response = $response->withHeader( $name, $value );
			}
			return $response;
		}
	}

	final class AISR_Network_Exception extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
		private \Psr\Http\Message\RequestInterface $request;

		public function __construct( string $message, \Psr\Http\Message\RequestInterface $request ) {
			parent::__construct( $message );
			$this->request = $request;
		}

		public function getRequest(): \Psr\Http\Message\RequestInterface {
			return $this->request;
		}
	}

	/** Discovery strategy: our client for PSR-18, Nyholm for PSR-17. */
	final class AISR_Discovery_Strategy implements \Http\Discovery\Strategy\DiscoveryStrategy {

		public static function getCandidates( $type ) {
			if ( \Psr\Http\Client\ClientInterface::class === $type ) {
				return array(
					array(
						'class'     => static function () {
							// Tests and staging can swap the client (e.g. a mock) through this filter.
							return apply_filters( 'aisr_http_client', new AISR_WP_Http_Client() );
						},
						'condition' => true,
					),
				);
			}
			$psr17 = array(
				\Psr\Http\Message\RequestFactoryInterface::class,
				\Psr\Http\Message\ResponseFactoryInterface::class,
				\Psr\Http\Message\StreamFactoryInterface::class,
				\Psr\Http\Message\UriFactoryInterface::class,
				\Psr\Http\Message\ServerRequestFactoryInterface::class,
				\Psr\Http\Message\UploadedFileFactoryInterface::class,
			);
			if ( in_array( $type, $psr17, true ) ) {
				return array( array( 'class' => \Nyholm\Psr7\Factory\Psr17Factory::class, 'condition' => true ) );
			}
			return array();
		}
	}

	\Http\Discovery\ClassDiscovery::prependStrategy( AISR_Discovery_Strategy::class );
}
