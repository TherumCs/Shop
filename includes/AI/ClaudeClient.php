<?php
/**
 * Shop by Therum — Claude API client.
 *
 * Minimal HTTP wrapper around Anthropic's Messages API. No SDK
 * dependency — just wp_remote_post + JSON. Supports text + image
 * content blocks and tool-use for structured output.
 *
 * Configuration:
 *   define( 'SHOP_ANTHROPIC_API_KEY', 'sk-ant-...' );
 *
 * Optional:
 *   define( 'SHOP_ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929' );
 *
 * Usage:
 *   $client = new ClaudeClient();
 *   $text = $client->complete(
 *       system:  'You extract product data from images.',
 *       blocks:  [
 *           [ 'type' => 'image', 'source' => [ ... ] ],
 *           [ 'type' => 'text',  'text'   => 'List every product.' ],
 *       ],
 *       tools:   [ ProductExtractionTool::definition() ],
 *   );
 *
 * Returns the model's text response or a tool_use block depending on
 * what the prompt asked for. Caller pattern-matches.
 */

namespace Shop\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ClaudeClient {

	private const ENDPOINT       = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION    = '2023-06-01';
	private const DEFAULT_MODEL  = 'claude-sonnet-4-5-20250929';
	private const TIMEOUT_S      = 120; // vision calls can be slow

	public static function isAvailable(): bool {
		return defined( 'SHOP_ANTHROPIC_API_KEY' ) && SHOP_ANTHROPIC_API_KEY !== '';
	}

	private function apiKey(): string {
		if ( ! self::isAvailable() ) {
			throw new \RuntimeException( 'SHOP_ANTHROPIC_API_KEY not configured.' );
		}
		return (string) SHOP_ANTHROPIC_API_KEY;
	}

	private function model(): string {
		return defined( 'SHOP_ANTHROPIC_MODEL' ) && SHOP_ANTHROPIC_MODEL !== ''
			? (string) SHOP_ANTHROPIC_MODEL
			: self::DEFAULT_MODEL;
	}

	/**
	 * Send a single-turn user message with the given content blocks.
	 *
	 * @param array<int, array<string,mixed>> $blocks  message content blocks
	 * @param array<int, array<string,mixed>> $tools   tool definitions (optional)
	 * @param int $maxTokens                           hard cap on output (default 4096)
	 *
	 * @return array<string,mixed>  the full response body
	 */
	public function complete(
		string $system,
		array $blocks,
		array $tools = [],
		int $maxTokens = 4096,
	): array {
		$payload = [
			'model'      => $this->model(),
			'max_tokens' => $maxTokens,
			'system'     => $system,
			'messages'   => [
				[ 'role' => 'user', 'content' => $blocks ],
			],
		];
		if ( $tools ) {
			$payload['tools']       = $tools;
			$payload['tool_choice'] = [ 'type' => 'auto' ];
		}

		$res = wp_remote_post( self::ENDPOINT, [
			'timeout' => self::TIMEOUT_S,
			'headers' => [
				'x-api-key'         => $this->apiKey(),
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body' => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Anthropic API error: ' . $res->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = (string) wp_remote_retrieve_body( $res );
		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( "Anthropic API status $status: $body" );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Anthropic returned invalid JSON.' );
		}
		return $decoded;
	}

	/**
	 * Extract the first tool_use block from a response (handy when you
	 * pinned the model to a single extraction tool).
	 *
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>|null  { name, input } or null if absent
	 */
	public static function toolUseBlock( array $response ): ?array {
		foreach ( (array) ( $response['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
				return [
					'name'  => (string) ( $block['name'] ?? '' ),
					'input' => (array)  ( $block['input'] ?? [] ),
				];
			}
		}
		return null;
	}

	/**
	 * Extract the concatenated text content from a response (when not
	 * using tools).
	 *
	 * @param array<string,mixed> $response
	 */
	public static function textOutput( array $response ): string {
		$out = '';
		foreach ( (array) ( $response['content'] ?? [] ) as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$out .= (string) ( $block['text'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Build an image content block from a local file path. Base64-encodes
	 * the file inline (Anthropic supports up to ~5 MB per image).
	 */
	public static function imageBlockFromPath( string $path, string $mime = 'image/jpeg' ): array {
		$bytes = (string) @file_get_contents( $path );
		if ( $bytes === '' ) {
			throw new \RuntimeException( "Could not read image: $path" );
		}
		return [
			'type'   => 'image',
			'source' => [
				'type'       => 'base64',
				'media_type' => $mime,
				'data'       => base64_encode( $bytes ),
			],
		];
	}
}
