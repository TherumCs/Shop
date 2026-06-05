<?php
/**
 * Shop by Therum — REST: pages.
 *
 *   GET    /shop/v1/admin/pages                 list
 *   POST   /shop/v1/admin/pages                 create  { title, kind, assigned_to }
 *   GET    /shop/v1/admin/pages/{id}            get one
 *   PUT    /shop/v1/admin/pages/{id}            save    { title?, slug?, status?, tree?, meta? }
 *   DELETE /shop/v1/admin/pages/{id}            delete
 *   POST   /shop/v1/admin/pages/{id}/render     preview render — returns HTML
 *
 *   GET    /shop/v1/elements                    public element catalog (for editor UI)
 *
 * Authoring routes gated; element-catalog read is public so the editor
 * can pre-load it.
 */

namespace Shop\Rest;

use Shop\Elements\ElementContext;
use Shop\Elements\ElementRegistry;
use Shop\Models\Page;
use Shop\Repositories\PageRepository;
use Shop\Services\BuilderAi;
use Shop\Services\PageRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class PagesController {

	public const NAMESPACE = 'shop/v1';

	public function __construct(
		private readonly PageRepository $pages,
		private readonly PageRenderer $renderer,
		private readonly ElementRegistry $elements,
		private readonly BuilderAi $ai,
	) {}

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/pages', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'list' ],   'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create' ], 'permission_callback' => $auth ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/pages/(?P<id>\d+)', [
			[ 'methods' => 'GET',    'callback' => [ $this, 'get' ],    'permission_callback' => $auth ],
			[ 'methods' => 'PUT',    'callback' => [ $this, 'save' ],   'permission_callback' => $auth ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete' ], 'permission_callback' => $auth ],
		] );

		register_rest_route( self::NAMESPACE, '/admin/pages/(?P<id>\d+)/render', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'render' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/elements', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'elements' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/builder/command', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'command' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NAMESPACE, '/admin/builder/chrome-active', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'setChromeActive' ],
			'permission_callback' => $auth,
		] );
	}

	/**
	 * Pin a header or footer page as the site-wide active chrome.
	 * Request: { kind: 'header'|'footer', id: int }
	 */
	public function setChromeActive( \WP_REST_Request $req ): \WP_REST_Response {
		$kind = (string) ( $req->get_param( 'kind' ) ?? '' );
		$id   = (int)    ( $req->get_param( 'id' )   ?? 0 );
		if ( ! in_array( $kind, [ 'header', 'footer' ], true ) || $id <= 0 ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid kind or id.' ], 400 );
		}
		// Verify the page exists and is of the right kind so we don't
		// silently pin a typo'd id.
		$page = $this->pages->findById( $id );
		if ( $page === null || $page->kind !== $kind ) {
			return new \WP_REST_Response( [ 'error' => 'Page not found or wrong kind.' ], 404 );
		}
		update_option( 'shop_active_' . $kind . '_id', $id );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * ⌘K command palette — converts natural language to tree ops.
	 *
	 * Request:  { prompt: string, tree: array }
	 * Response: { ops: array } or { error: string }
	 *
	 * Returns a 503-style error payload (not an HTTP error) when the
	 * Anthropic key isn't configured, so the palette can show a friendly
	 * message instead of a network-failure toast.
	 */
	public function command( \WP_REST_Request $req ): \WP_REST_Response {
		$prompt = (string) ( $req->get_param( 'prompt' ) ?? '' );
		$tree   = $req->get_param( 'tree' );
		if ( ! is_array( $tree ) ) $tree = [];

		if ( ! $this->ai->available() ) {
			return new \WP_REST_Response( [
				'error' => 'Anthropic API key not configured. Add SHOP_ANTHROPIC_API_KEY to wp-config.php.',
			], 200 );
		}

		try {
			$ops = $this->ai->commandToOps( $tree, $prompt );
			return new \WP_REST_Response( [ 'ops' => $ops ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 200 );
		}
	}

	public function list( \WP_REST_Request $req ): \WP_REST_Response {
		$kind   = $req->get_param( 'kind' )   !== null ? (string) $req->get_param( 'kind' )   : null;
		$status = $req->get_param( 'status' ) !== null ? (string) $req->get_param( 'status' ) : null;
		$pages  = $this->pages->list( $kind, $status, 200 );
		return new \WP_REST_Response( [
			'pages' => array_map( [ $this, 'serializeLight' ], $pages ),
		], 200 );
	}

	public function create( \WP_REST_Request $req ): \WP_REST_Response {
		$body  = $req->get_json_params() ?: [];
		$title = (string) ( $body['title'] ?? 'Untitled' );
		$kind  = in_array( (string) ( $body['kind'] ?? 'page' ), [ 'page','template','header','footer','part' ], true )
			? (string) $body['kind'] : 'page';
		$assigned = isset( $body['assigned_to'] ) ? (string) $body['assigned_to'] : null;
		$page  = $this->pages->create( $title, $kind, $assigned );
		return new \WP_REST_Response( $this->serialize( $page ), 201 );
	}

	public function get( \WP_REST_Request $req ): \WP_REST_Response {
		$page = $this->pages->findById( (int) $req->get_param( 'id' ) );
		if ( $page === null ) return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		return new \WP_REST_Response( $this->serialize( $page ), 200 );
	}

	public function save( \WP_REST_Request $req ): \WP_REST_Response {
		$id    = (int) $req->get_param( 'id' );
		$patch = $req->get_json_params() ?: [];
		try {
			$page = $this->pages->save( $id, $patch );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => [ 'message' => $e->getMessage() ] ], 422 );
		}
		return new \WP_REST_Response( $this->serialize( $page ), 200 );
	}

	public function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$this->pages->delete( (int) $req->get_param( 'id' ) );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public function render( \WP_REST_Request $req ): \WP_REST_Response {
		$page = $this->pages->findById( (int) $req->get_param( 'id' ) );
		if ( $page === null ) return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		$body = $req->get_json_params() ?: [];
		$tree = isset( $body['tree'] ) && is_array( $body['tree'] ) ? $body['tree'] : $page->tree;

		$context = new ElementContext(
			productId: isset( $body['product_id'] ) ? (int) $body['product_id'] : null,
		);
		$html = $this->renderer->withEditorMode( true )->render( $tree, $context );
		return new \WP_REST_Response( [
			'html'       => $html,
			'needs_js'   => $this->renderer->pageNeededJs(),
		], 200 );
	}

	public function elements( \WP_REST_Request $req ): \WP_REST_Response {
		$out = [];
		foreach ( $this->elements->all() as $el ) {
			$out[] = [
				'id'       => $el->id(),
				'name'     => $el->name(),
				'category' => $el->category(),
				'icon'     => $el->icon(),
				'needs_js' => $el->needsJs(),
				'controls' => $el->controls(),
			];
		}
		return new \WP_REST_Response( [ 'elements' => $out ], 200 );
	}

	// ─── Serializers ─────────────────────────────────────────────────────

	/** @return array<string,mixed> */
	private function serialize( Page $p ): array {
		return array_merge( $this->serializeLight( $p ), [
			'tree' => $p->tree,
			'meta' => $p->meta,
		] );
	}

	/** @return array<string,mixed> */
	private function serializeLight( Page $p ): array {
		return [
			'id'           => $p->id,
			'uuid'         => $p->uuid,
			'slug'         => $p->slug,
			'title'        => $p->title,
			'kind'         => $p->kind,
			'assigned_to'  => $p->assignedTo,
			'status'       => $p->status,
			'updated_at'   => $p->updatedAt,
			'published_at' => $p->publishedAt,
		];
	}
}
