<?php declare(strict_types=1);

namespace Taproot\Micropub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

const MICROPUB_ERROR_CODES = ['invalid_request', 'unauthorized', 'insufficient_scope', 'forbidden'];

/**
 * Micropub Adapter Abstract Superclass
 * 
 * Subclass this class and implement the various `*Callback()` methods to handle different 
 * types of micropub request.
 * 
 * Then, handling a micropub request is as simple as 
 *     
 *     $mp = new YourMicropubAdapter();
 *     return $mp->handleRequest($request);
 * 
 * The same goes for a media endpoint:
 * 
 *     return $mp->handleMediaEndpointRequest($request);
 * 
 * Subclasses **must** implement the abstract callback method `verifyAccessToken()` in order
 * to have a functional micropub endpoint. All other callback methods are optional, and 
 * their functionality is enabled if a subclass implements them. Feel free to define your own
 * constructor, and make any implementation-specific objects available to callbacks by storing
 * them as properties.
 * 
 * Each callback is passed data corresponding to the type of micropub request dispatched
 * to it, but can also access the original request via `$this->request`. Data about the
 * currently authenticated user is available in `$this->user`.
 * 
 * Each callback return data in a format defined by the callback, which will be 
 * converted into the appropriate HTTP Response. Returning an instance of `ResponseInterface`
 * from a callback will cause that response to immediately be returned unchanged. Most callbacks
 * will also automatically convert an array return value into a JSON response, and will convert
 * the following string error codes into properly formatted micropub error responses:
 * 
 * * `'invalid_request'`
 * * `'insufficient_scope'`
 * * `'unauthorized'`
 * * `'forbidden'`
 * 
 * In practise, you’ll mostly be returning the first two, as the others are handled automatically.
 * 
 * MicropubAdapter **does not handle any authorization or permissions**, as which users and
 * scopes have what permissions depends on your implementation. It’s up to you to confirm that
 * the current access token has sufficient scope and permissions to carry out any given action
 * within your callback, and return `'insufficient_scope'` or your own custom instance of
 * `ResponseInterface`.
 * 
 * Most callbacks halt execution, but some are optional. Returning a falsy value from these 
 * optional callbacks continues execution uninterrupted. This is usually to allow you to pre-
 * empt standard micropub handling and implement custom extensions.
 * 
 * MicropubAdapter works with PSR-7 HTTP Interfaces. Specifically, expects an object implementing
 * `ServerRequestInterface`, and will return an object implemeting `ResponseInterface`. If you 
 * want to return responses from your callbacks, you’re free to use any suitable implementation.
 * For internally-generated responses, `Nyholm\Psr7\Response` is used.
 * 
 * If you’re not using a framework which works with PSR-7 objects, you’ll have to convert 
 * whatever request data you have into something implementing PSR-7 `ServerRequestInterface`,
 * and convert the returned `ResponseInterface`s to something you can work with.
 * 
 * @link https://micropub.spec.indieweb.org/
 * @link https://indieweb.org/micropub
 */
abstract class MicropubAdapter {

	/**
	 * @var array $user The validated access_token, made available for use in callback methods.
	 */
	public $user;

	/**
	 * @var RequestInterface $request The current request, made available for use in callback methods.
	 */
	public $request;
	
	/**
	 * @var null|LoggerInterface $logger The logger used by MicropubAdaptor for internal logging.
	 */
	public $logger;

	/**
	 * @var string[] $errorMessages An array mapping micropub and adapter-specific error codes to human-friendly descriptions.
	 */
	private $errorMessages = [
		// Built-in micropub error types
		'insufficient_scope' => 'Your access token does not grant the scope required for this action.',
		'forbidden' => 'The authenticated user does not have permission to perform this request.',
		'unauthorized' => 'The request did not provide an access token.',
		'invalid_request' => 'The request was invalid.',
		// Custom errors
		'access_token_invalid' => 'The provided access token could not be verified.',
		'missing_url_parameter' => 'The request did not provide the required url parameter.',
		'post_with_given_url_not_found' => 'A post with the given URL could not be found.',
		'not_implemented' => 'This functionality is not implemented.',
	];

	/**
	 * Verify Access Token Callback
	 * 
	 * Given an access token, attempt to verify it.
	 * 
	 * * If it’s valid, return an array to be stored in `$this->user`, which typically looks
	 *   something like this:
	 *   
	 *       [
	 *         'me' => 'https://example.com',
	 *         'client_id' => 'https://clientapp.example',
	 *         'scope' => ['array', 'of', 'granted', 'scopes'],
	 *         'date_issued' => \Datetime
	 *       ]
	 * * If the toke in invalid, return one of the following:
	 *     * `false`, which will be converted into an appropriate error message.
	 *     * `'forbidden'`, which will be converted into an appropriate error message.
	 *     * An array to be converted into an error response, with the form:
	 *       
	 *           [
	 *             'error': 'forbidden'
	 *             'error_description': 'Your custom error description' 
	 *           ]
	 *     * Your own instance of `ResponseInterface`
	 * 
	 * 
	 * MicropubAdapter treats the data as being opaque, and simply makes it
	 * available to your callback methods for further processing, so you’re free
	 * to structure it however you want.
	 * 
	 * @param string $token The Authentication: Bearer access token.
	 * @return array|string|false|ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#authentication-0
	 * @api
	 */
	abstract public function verifyAccessTokenCallback(string $token);
	
	/**
	 * Micropub Extension Callback
	 * 
	 * This callback is called after an access token is verified, but before any micropub-
	 * specific handling takes place. This is the place to implement support for micropub
	 * extensions.
	 * 
	 * If a falsy value is returned, the request continues to be handled as a regular 
	 * micropub request. If it returns a truthy value (either a MP error code, an array to
	 * be returned as JSON, or a ready-made ResponseInterface), request handling is halted
	 * and the returned value is converted into a response and returned.
	 * 
	 * @param ServerRequestInterface $request
	 * @return false|array|string|ResponseInterface
	 * @link https://indieweb.org/Micropub-extensions
	 * @api
	 */
	public function extensionCallback(ServerRequestInterface $request) {
		// Default implementation: no-op;
		return false;
	}

	/**
	 * Configuration Query Callback
	 * 
	 * Handle a GET q=config query. Should return either a custom ResponseInterface, or an
	 * array structure conforming to the micropub specification, e.g.:
	 * 
	 *     [
	 *       'media-endpoint' => 'http://example.com/your-media-endpoint',
	 *       'syndicate-to' => [[
	 *         'uid' => 'https://myfavoritesocialnetwork.example/aaronpk', // Required
	 *         'name' => 'aaronpk on myfavoritesocialnetwork', // Required
	 *         'service' => [ // Optional
	 *           'name' => 'My Favorite Social Network',
	 *           'url' => 'https://myfavoritesocialnetwork.example/',
	 *           'photo' => 'https://myfavoritesocialnetwork.example/img/icon.png', 
	 *         ],
	 *         'user' => [ // Optional
	 *           'name' => 'aaronpk',
	 *           'photo' => 'https://myfavoritesocialnetwork.example/aaronpk',
	 *           'url' => 'https://myfavoritesocialnetwork.example/aaronpk/photo.jpg'
	 *         ]
	 *       ]]
	 *     ]
	 * 
	 * The results from this function are also used to respond to syndicate-to queries. If 
	 * a raw ResponseInterface is returned, that will be used as-is. If an array structure
	 * is returned, syndicate-to queries will extract the syndicate-to information and 
	 * return just that.
	 * 
	 * @param array $params The unaltered query string parameters from the request.
	 * @return array|string|ResponseInterface Return either an array with config data, a micropub error string, or a ResponseInterface to short-circuit
	 * @link https://micropub.spec.indieweb.org/#configuration
	 * @api
	 */
	public function configurationQueryCallback(array $params) {
		// Default response: an empty JSON object.
		return $this->toResponse(null);
	}

	/**
	 * Source Query Callback
	 * 
	 * Handle a GET q=source query.
	 * 
	 * The callback should return a microformats2 canonical JSON representation
	 * of the post identified by $url, either as an array or as a ready-made ResponseInterface.
	 * 
	 * If the post identified by $url cannot be found, returning false will return a
	 * correctly-formatted error response. Alternatively, you can return a string micropub
	 * error code (e.g. `'invalid_request'`) or your own instance of `ResponseInterface`.
	 * 
	 * @param string $url The URL of the post for which to return properties.
	 * @param array|null $properties = null The list of properties to return (all if null)
	 * @return array|false|string|ResponseInterface Return either an array with canonical mf2 data, false if the post could not be found, a micropub error string, or a ResponseInterface to short-circuit.
	 * @link https://micropub.spec.indieweb.org/#source-content
	 * @api
	 */
	public function sourceQueryCallback(string $url, array $properties = null) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Delete Callback
	 * 
	 * Handle a POST action=delete request.
   *
	 * * Look for a post identified by the $url parameter. 
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit deletion, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be deleted or is already deleted, delete it and return true.
	 * 
	 * @param string $url The URL of the post to be deleted.
	 * @return string|true|array|ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#delete
	 * @api
	 */
	public function deleteCallback(string $url) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Undelete Callback
	 * 
	 * Handle a POST action=undelete request.
	 * 
	 * * Look for a post identified by the $url parameter.
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit undeletion, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be undeleted, do so. Return true for success, or a URL if the
	 *   undeletion caused the post’s URL to change.
	 * 
	 * @param string $url The URL of the post to be undeleted.
	 * @return string|true|array|ResponseInterface true on basic success, otherwise either an error string, or a URL if the undeletion caused the post’s location to change.
	 * @link https://micropub.spec.indieweb.org/#delete
	 * @api
	 */
	public function undeleteCallback(string $url) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Update Callback
	 * 
	 * Handles a POST action=update request.
	 * 
	 * * Look for a post identified by the $url parameter.
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit updates, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be updated, do so. Return true for basic success, or a URL if the
	 *   undeletion caused the post’s URL to change.
	 * 
	 * @param string $url The URL of the post to be updated.
	 * @param array $actions The parsed body of the request, containing 'replace', 'add' and/or 'delete' keys describing the operations to perfom on the post.
	 * @return true|string|array|ResponseInterface Return true for a basic success, a micropub error string, an array to be converted to a JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#update
	 * @api
	 */
	public function updateCallback(string $url, array $actions) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Create Callback
	 * 
	 * Handles a create request. JSON parameters are left unchanged, urlencoded
	 * form parameters are normalized into canonical microformats-2 JSON form.
	 *
	 * * If the current access token scope doesn’t permit updates, return either
	 *   `'insufficient_scope'`, an array with `'error'` and `'error_description'`
	 *   keys, or your own ResponseInterface.
	 * * Create the post.
	 * * On an error, return either a micropub error code to be upgraded into a 
	 *   full error response, or your own ResponseInterface.
	 * * On success, return either the URL of the created post to be upgraded into 
	 *   a HTTP 201 success response, or your own ResponseInterface.
	 * 
	 * @param array $data The data to create a post with in canonical MF2 structure
	 * @param array $uploadedFiles an associative array mapping property names to UploadedFileInterface objects, or arrays thereof
	 * @return string|array|ResponseInterface A URL on success, a micropub error code, an array to be returned as JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#create
	 * @api
	 */
	public function createCallback(array $data, array $uploadedFiles) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Media Endpoint Callback
	 * 
	 * To handle file upload requests:
	 * 
	 * * If the current access token scope doesn’t permit uploads, return either
	 *   `'insufficient_scope'`, an array with `'error'` and `'error_description'`
	 *   keys, or your own ResponseInterface.
	 * * Handle the uploaded file.
	 * * On an error, return either a micropub error code to be upgraded into a 
	 *   full error response, or your own ResponseInterface.
	 * * On success, return either the URL of the created URL to be upgraded into 
	 *   a HTTP 201 success response, or your own ResponseInterface.
	 * 
	 * @param UploadedFileInterface $file The file to upload
	 * @return string|array|ResponseInterface Return the URL of the uploaded file on success, a micropub error code to be upgraded into an error response, an array for a JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#media-endpoint
	 * @api
	 */
	public function mediaEndpointCallback(UploadedFileInterface $file) {
		// Default implementation: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Micropub Media Endpoint Extension Callback
	 * 
	 * This callback is called after an access token is verified, but before any media-
	 * endpoint-specific handling takes place. This is the place to implement support 
	 * for micropub media endpoint extensions.
	 * 
	 * If a falsy value is returned, the request continues to be handled as a regular 
	 * micropub request. If it returns a truthy value (either a MP error code, an array to
	 * be returned as JSON, or a ready-made ResponseInterface), request handling is halted
	 * and the returned value is converted into a response and returned.
	 * 
	 * @param ServerRequestInterface $request
	 * @return false|array|string|ResponseInterface
	 * @link https://indieweb.org/Micropub-extensions
	 */
	public function mediaEndpointExtensionCallback(ServerRequestInterface $request) {
		// Default implementation: no-op.
		return false;
	}

	/**
	 * Get Logger
	 * 
	 * Returns an instance of Psr\LoggerInterface, used for logging. Override to
	 * provide with your logger of choice.
	 * 
	 * @return \Psr\Log\LoggerInterface
	 */
	protected function getLogger(): LoggerInterface {
		if (!isset($this->logger)) {
			$this->logger = new NullLogger();
		}
		return $this->logger;
	}

	/**
	 * Handle Micropub Request
	 * 
	 * Handle an incoming request to a micropub endpoint, performing error checking and 
	 * handing execution off to the appropriate callback.
	 * 
	 * `$this->request` is set to the value of the `$request` argument, for use within
	 * callbacks. If the access token could be verified, `$this->user` is set to the value
	 * returned from `verifyAccessTokenCallback()` for use within callbacks.
	 * 
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function handleRequest(ServerRequestInterface $request) {
		// Make $request available to callbacks.
		$this->request = $request;

		$logger = $this->getLogger();
		
		// Get and verify auth token.
		$accessToken = getAccessToken($request);
		if ($accessToken === null) {
			$logger->warning($this->errorMessages['unauthorized']);
			return $this->toResponse('unauthorized');
		}

		$accessTokenResult = $this->verifyAccessTokenCallback($accessToken);
		if ($accessTokenResult instanceof ResponseInterface) {
			return $accessTokenResult; // Short-circuit.
		} elseif (is_array($accessTokenResult)) {
			// Log success.
			$logger->info('Access token verified successfully.', ['user' => $accessTokenResult]);
			$this->user = $accessTokenResult;
		} else {
			// Log error, return not authorized response.
			$logger->error($this->errorMessages['access_token_invalid']);
			return $this->toResponse('forbidden');
		}
		
		// Give subclasses an opportunity to pre-emptively handle any extension cases before moving on to
		// standard micropub handling.
		$extensionCallbackResult = $this->extensionCallback($request);
		if ($extensionCallbackResult) {
			return $this->toResponse($extensionCallbackResult);
		}

		// Check against method.
		if (strtolower($request->getMethod()) == 'get') {
			$queryParams = $request->getQueryParams();

			if (isset($queryParams['q']) and is_string($queryParams['q'])) {
				$q = $queryParams['q'];
				if ($q == 'config') {
					// Handle configuration query.
					$logger->info('Handling config query', $queryParams);
					return $this->toResponse($this->configurationQueryCallback($queryParams));
				} elseif ($q == 'source') {
					// Handle source query.
					$logger->info('Handling source query', $queryParams);

					// Normalize properties([]) paramter.
					if (isset($queryParams['properties']) and is_array($queryParams['properties'])) {
						$sourceProperties = $queryParams['properties'];
					} elseif (isset($queryParams['properties']) and is_string($queryParams['properties'])) {
						$sourceProperties = [$queryParams['properties']];
					} else {
						$sourceProperties = null;
					}

					// Check for a valid (string) url parameter.
					if (!isset($queryParams['url']) or !is_string($queryParams['url'])) {
						$logger->error($this->errorMessages['missing_url_parameter']);
						return $this->toResponse(json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]), 400);
					}

					$sourceQueryResult = $this->sourceQueryCallback($queryParams['url'], $sourceProperties);
					if ($sourceQueryResult === false) {
						// Returning false is a shortcut for an “invalid URL” error.
						$logger->error($this->errorMessages['post_with_given_url_not_found']);
						$sourceQueryResult = [
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['post_with_given_url_not_found']
						];
					}

					return $this->toResponse($sourceQueryResult);
				} elseif ($q == 'syndicate-to') {
					// Handle syndicate-to query via the configuration query callback.
					$logger->info('Handling syndicate-to query.', $queryParams);
					$configQueryResult = $this->configurationQueryCallback($queryParams);
					if ($configQueryResult instanceof ResponseInterface) {
						// Short-circuit, assume that the response from q=config will suffice for q=syndicate-to.
						return $configQueryResult;
					} elseif (is_array($configQueryResult) and array_key_exists('syndicate-to', $configQueryResult)) {
						return new Response(200, ['content-type' => 'application/json'], json_encode([
							'syndicate-to' => $configQueryResult['syndicate-to']
						]));
					} else {
						// We don’t have anything to return, so return an empty result.
						return new Response(200, ['content-type' => 'application/json'], '{"syndicate-to": []}');
					}
				}
			}

			// We weren’t able to handle this GET request.
			$logger->error('Micropub endpoint was not able to handle GET request', $queryParams);
			return $this->toResponse('invalid_request');
		} elseif (strtolower($request->getMethod()) == 'post') {
			$contentType = $request->getHeaderLine('content-type');
			$jsonRequest = $contentType == 'application/json';
			
			// Get a parsed body sufficient to determine the nature of the request.
			if ($jsonRequest) {
				$parsedBody = json_decode((string) $request->getBody(), true);
			} else {
				$parsedBody = $request->getParsedBody();
			}

			// The rest of the code assumes that parsedBody is an array. If we don’t have an array by now,
			// the request is invalid.
			if (!is_array($parsedBody)) {
				return $this->toResponse('invalid_request');
			}

			// Prevent the access_token from being stored.
			unset($parsedBody['access_token']);
			
			// Check for action.
			if (isset($parsedBody['action']) and is_string($parsedBody['action'])) {
				$action = $parsedBody['action'];
				if ($action == 'delete') {
					// Handle delete request.
					$logger->info('Handling delete request.', $parsedBody);
					if (isset($parsedBody['url']) and is_string($parsedBody['url'])) {
						$deleteResult = $this->deleteCallback($parsedBody['url']);
						if ($deleteResult === true) {
							// If the delete was successful, respond with an empty 204 response.
							return $this->toResponse('', 204);
						} else {
							return $this->toResponse($deleteResult);
						}
					} else {
						$logger->warning($this->errorMessages['missing_url_parameter']);
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}
					
				} elseif ($action == 'undelete') {
					// Handle undelete request.
					if (isset($parsedBody['url']) and is_string($parsedBody['url'])) {
						$undeleteResult = $this->undeleteCallback($parsedBody['url']);
						if ($undeleteResult === true) {
							// If the delete was successful, respond with an empty 204 response.
							return $this->toResponse('', 204);
						} elseif (is_string($undeleteResult) and !in_array($undeleteResult, MICROPUB_ERROR_CODES)) {
							// The non-error-code string returned from undelete is the URL of the new location of the
							// undeleted content.
							return new Response(201, ['location' => $undeleteResult]);
						} else {
							return $this->toResponse($undeleteResult);
						}
					} else {
						$logger->warning($this->errorMessages['missing_url_parameter']);
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}
				} elseif ($action == 'update') {
					// Handle update request.
					// Check for the required url parameter.
					if (!isset($parsedBody['url']) or !is_string($parsedBody['url'])) {
						$logger->warning("An update request had a missing or invalid url parameter.");
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}

					// Check that the three possible update action parameters are all arrays.
					foreach (['replace', 'add', 'delete'] as $updateAction) {
						if (isset($parsedBody[$updateAction]) and !is_array($parsedBody[$updateAction])) {
							$logger->warning("An update request had an invalid (non-array) $updateAction", [$updateAction => $parsedBody[$updateAction]]);
							return $this->toResponse('invalid_request');
						}
					}
					
					$updateResult = $this->updateCallback($parsedBody['url'], $parsedBody);
					if ($updateResult === true) {
						// Basic success.
						return $this->toResponse('', 204);
					} elseif (is_string($updateResult) and !in_array($updateResult, MICROPUB_ERROR_CODES)) {
						// The non-error-code string returned from update is the URL of the new location of the
						// undeleted content.
						return new Response(201, ['location' => $updateResult]);
					} else {
						return $this->toResponse($updateResult);
					}
				}

				// An unknown action was provided. Return invalid_request.
				$logger->error('An unknown action parameter was provided.', $parsedBody);
				return $this->toResponse('invalid_request');
			}

			// Assume that the request is a Create request.
			// If we’re dealing with an x-www-form-urlencoded or multipart/form-data request,
			// normalise form data to match JSON structure.
			if (!$jsonRequest) {
				$logger->info('Normalizing URL-encoded data into canonical JSON format.');
				$parsedBody = normalizeUrlencodedCreateRequest($parsedBody);
			}

			// Pass data off to create callback.
			$createResponse = $this->createCallback($parsedBody, $request->getUploadedFiles());
			if (is_string($createResponse) and !in_array($createResponse, MICROPUB_ERROR_CODES)) {
				// Success, return HTTP 201 with Location header.
				return new Response(201, ['location' => $createResponse]);
			} else {
				return $this->toResponse($createResponse);
			}

		}
		
		// Request method was something other than GET or POST.
		$logger->error('The request had a method other than POST or GET.', ['method' => $request->getMethod()]);
		return $this->toResponse('invalid_request');
	}

	/**
	 * Handle Media Endpoint Request
	 * 
	 * Handle a request to a micropub media-endpoint.
	 * 
	 * As with `handleRequest()`, `$this->request` and `$this->user` are made available
	 * for use within callbacks.
	 * 
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function handleMediaEndpointRequest(ServerRequestInterface $request) {
		$logger = $this->getLogger();
		$this->request = $request;

		// Get and verify auth token.
		$accessToken = getAccessToken($request);
		if ($accessToken === null) {
			$logger->warning($this->errorMessages['unauthorized']);
			return new Response(401, ['content-type' => 'application/json'], json_encode([
				'error' => 'unauthorized',
				'error_description' => $this->errorMessages['unauthorized']
			]));
		}

		$accessTokenResult = $this->verifyAccessTokenCallback($accessToken);
		if ($accessTokenResult instanceof ResponseInterface) {
			return $accessTokenResult; // Short-circuit.
		} elseif (is_array($accessTokenResult)) {
			// Log success.
			$logger->info('Access token verified successfully.', ['user' => $accessTokenResult]);
			$this->user = $accessTokenResult;
		} else {
			// Log error, return not authorized response.
			$logger->error($this->errorMessages['access_token_invalid']);
			return new Response(403, ['content-type' => 'application/json'], json_encode([
				'error' => 'forbidden',
				'error_description' => $this->errorMessages['access_token_invalid']
			]));
		}

		// Give implementations a chance to pre-empt regular media endpoint handling, in order
		// to implement extensions.
		$mediaEndpointExtensionResult = $this->mediaEndpointExtensionCallback($request);
		if ($mediaEndpointExtensionResult) {
			return $this->toResponse($mediaEndpointExtensionResult);
		}

		// Only support POST requests to the media endpoint.
		if (strtolower($request->getMethod()) != 'post') {
			$logger->error('Got a non-POST request to the media endpoint', ['method' => $request->getMethod()]);
			return $this->toResponse('invalid_request');
		}

		// Look for the presence of an uploaded file called 'file'
		$uploadedFiles = $request->getUploadedFiles();
		if (isset($uploadedFiles['file']) and $uploadedFiles['file'] instanceof UploadedFileInterface) {
			$mediaCallbackResult = $this->mediaEndpointCallback($uploadedFiles['file']);

			if ($mediaCallbackResult) {
				if (is_string($mediaCallbackResult) and !in_array($mediaCallbackResult, MICROPUB_ERROR_CODES)) {
					// Success! Return an HTTP 201 response with the location header.
					return new Response(201, ['location' => $mediaCallbackResult]);
				}

				// Otherwise, handle whatever it is we got.
				return $this->toResponse($mediaCallbackResult);
			}
		}

		// Either no file was provided, or mediaEndpointCallback returned a falsy value.
		return $this->toResponse('invalid_request');
	}

	/**
	 * To Response
	 * 
	 * Intelligently convert various shortcuts into a suitable instance of
	 * ResponseInterface. Existing ResponseInterfaces are passed through
	 * without alteration.
	 * 
	 * @param null|string|array|ResponseInterface $resultOrResponse
	 * @param int $status=200
	 * @return ResponseInterface
	 */
	private function toResponse($resultOrResponse, int $status=200): ResponseInterface {
		if ($resultOrResponse instanceof ResponseInterface) {
			return $resultOrResponse;
		}

		// Convert micropub error messages into error responses.
		if (is_string($resultOrResponse) && in_array($resultOrResponse, MICROPUB_ERROR_CODES)) {
			$resultOrResponse = [
				'error' => $resultOrResponse,
				'error_description' => $this->errorMessages[$resultOrResponse]
			];
		}

		if ($resultOrResponse === null) {
			$resultOrResponse = '{}'; // Default to an empty object response if none given.
		} elseif (is_array($resultOrResponse)) {
			// If this is a known error response, adjust the status accordingly.
			if (array_key_exists('error', $resultOrResponse)) {
				if ($resultOrResponse['error'] == 'invalid_request') {
					$status = 400;
				} elseif ($resultOrResponse['error'] == 'unauthorized') {
					$status = 401;
				} elseif ($resultOrResponse['error'] == 'insufficient_scope') {
					$status = 403;
				} elseif ($resultOrResponse['error'] == 'forbidden') {
					$status = 403;
				}
			}
			$resultOrResponse = json_encode($resultOrResponse);
		}
		return new Response($status, ['content-type' => 'application/json'], $resultOrResponse);
	}
}

/**
 * Get Access Token
 * 
 * Given a request, return the Micropub access token, or null.
 * 
 * @return string|null
 */
function getAccessToken(ServerRequestInterface $request) {
	if ($request->hasHeader('authorization')) {
		foreach ($request->getHeader('authorization') as $authVal) {
			if (strtolower(substr($authVal, 0, 6)) == 'bearer') {
				return substr($authVal, 7);
			}
		}
	}
	
	$parsedBody = $request->getParsedBody();
	if (is_array($parsedBody) and array_key_exists('access_token', $parsedBody) and is_string($parsedBody['access_token'])) {
		return $parsedBody['access_token'];
	}

	return null;
}

/**
 * Normalize URL-encoded Create Request
 * 
 * Given an array of PHP-parsed form parameters (such as from $_POST), convert
 * them into canonical microformats2 format.
 * 
 * @param array $body
 * @return array 
 */
function normalizeUrlencodedCreateRequest(array $body) {
	$result = [
		'type' => ['h-entry'],
		'properties' => []
	];

	foreach ($body as $key => $value) {
		if ($key == 'h') {
			$result['type'] = ["h-$value"];
		} elseif (is_array($value)) {
			$result['properties'][$key] = $value;
		} else {
			$result['properties'][$key] = [$value];
		}
	}

	return $result;
}
