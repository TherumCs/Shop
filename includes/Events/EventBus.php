<?php
/**
 * Shop by Therum — typed event bus.
 *
 * Two dispatch modes:
 *
 *   dispatch(Event)  — synchronous, inline. Subscribers run in registration
 *                      order. Throws on subscriber exception (caller decides
 *                      what to do — usually let it bubble inside a tx).
 *
 *   queue(Event)     — fire-and-forget. Enqueues the event as a WP cron job
 *                      via Action Scheduler if available, else wp-cron. The
 *                      handler runs in a background request.
 *
 * Subscription:
 *
 *   $bus->on(Event::class, callable);
 *
 * Each subscriber gets a single typed Event argument. No payloads-as-array,
 * no `apply_filters` chain — straight method dispatch.
 *
 * No priorities. Subscribers run in registration order. If you need ordered
 * compute, that's what Pipelines are for — events are for side-effects.
 */

namespace Shop\Events;

if ( ! defined( 'ABSPATH' ) ) exit;

final class EventBus {

	/** @var array<class-string<Event>, array<int, callable>> */
	private array $subscribers = [];

	/**
	 * Register a subscriber for an event class.
	 *
	 * @template T of Event
	 * @param class-string<T>  $eventClass
	 * @param callable(T): void $subscriber
	 */
	public function on( string $eventClass, callable $subscriber ): void {
		$this->subscribers[ $eventClass ][] = $subscriber;
	}

	/**
	 * Dispatch synchronously. Subscribers run in registration order.
	 * Throws if a subscriber throws — caller decides what to catch.
	 */
	public function dispatch( Event $event ): void {
		$class = $event::class;
		if ( empty( $this->subscribers[ $class ] ) ) {
			return;
		}
		foreach ( $this->subscribers[ $class ] as $subscriber ) {
			$subscriber( $event );
		}
	}

	/**
	 * Enqueue for async dispatch. Returns the action ID if Action Scheduler
	 * is available, otherwise schedules a one-off wp-cron event and returns 0.
	 *
	 * Subscribers register against the event class as usual; the worker
	 * reconstructs the event from its serialized form before dispatching.
	 */
	public function queue( Event $event, int $delaySeconds = 0 ): int {
		$payload = [
			'class' => $event::class,
			'data'  => self::serialize( $event ),
		];

		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (int) as_schedule_single_action(
				time() + max( 0, $delaySeconds ),
				'shop_queued_event',
				[ $payload ],
				'shop'
			);
		}

		wp_schedule_single_event( time() + max( 0, $delaySeconds ), 'shop_queued_event', [ $payload ] );
		return 0;
	}

	/**
	 * Worker callback — receives a queued payload, reconstructs the event,
	 * and dispatches it synchronously. Registered to both 'shop_queued_event'
	 * (wp-cron) and the Action Scheduler hook by the bootstrap.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function handleQueued( array $payload ): void {
		$class = $payload['class'] ?? '';
		$data  = $payload['data']  ?? [];
		if ( ! is_string( $class ) || ! class_exists( $class ) || ! is_array( $data ) ) {
			return;
		}
		if ( ! is_subclass_of( $class, Event::class ) ) {
			return;
		}
		$event = self::deserialize( $class, $data );
		$this->dispatch( $event );
	}

	/**
	 * Serialize a typed event to a plain array (scalars + nested arrays only)
	 * so it survives wp-cron / Action Scheduler storage.
	 *
	 * @return array<string,mixed>
	 */
	private static function serialize( Event $event ): array {
		$ref  = new \ReflectionObject( $event );
		$data = [];
		foreach ( $ref->getProperties( \ReflectionProperty::IS_PUBLIC ) as $prop ) {
			$value = $prop->getValue( $event );
			$data[ $prop->getName() ] = self::scalarize( $value );
		}
		return $data;
	}

	/**
	 * Rebuild a typed event from its array form. Assumes the event has a
	 * constructor with named-parameter compatible signature (i.e. every
	 * public property's name matches a constructor param).
	 *
	 * @param class-string<Event>    $class
	 * @param array<string,mixed>    $data
	 */
	private static function deserialize( string $class, array $data ): Event {
		// Filter to only known constructor params — events may evolve.
		$ref    = new \ReflectionClass( $class );
		$ctor   = $ref->getConstructor();
		if ( $ctor === null ) {
			return new $class();
		}
		$params = [];
		foreach ( $ctor->getParameters() as $p ) {
			$name = $p->getName();
			if ( array_key_exists( $name, $data ) ) {
				$params[ $name ] = $data[ $name ];
			} elseif ( $p->isDefaultValueAvailable() ) {
				$params[ $name ] = $p->getDefaultValue();
			}
		}
		/** @var Event */
		return $ref->newInstance( ...$params );
	}

	private static function scalarize( mixed $value ): mixed {
		if ( is_scalar( $value ) || $value === null ) return $value;
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'scalarize' ], $value );
		}
		// Don't try to serialize complex objects through the queue. If you
		// need a non-scalar in a queued event, store the ID and re-fetch
		// inside the subscriber.
		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}
		return null;
	}
}
