/**
 * Counter by Therum — Taxonomy ordering (drag-drop).
 *
 * Uses Sortable.js (vendored or CDN) for drag-drop functionality.
 * Syncs changes back to REST API via /counter/v1/admin/taxonomies/{type}
 */

(function () {
	'use strict';

	const config = window.CounterTaxonomyOrderConfig;
	if (!config) return;

	class TaxonomyOrderer {
		constructor() {
			this.taxonomy = config.taxonomy;
			this.restUrl = config.restUrl;
			this.nonce = config.nonce;
			this.listEl = document.getElementById('counter-taxonomy-tree');
			this.saveBtn = document.getElementById('counter-save-order');
			this.spinner = document.getElementById('counter-order-spinner');
			this.message = document.getElementById('counter-order-message');

			if (!this.listEl || !this.saveBtn) return;

			this.init();
		}

		init() {
			// Initialize Sortable.js on the tree
			this.initSortable();

			// Save button handler
			this.saveBtn.addEventListener('click', () => this.save());

			// Also save on Enter key (accessibility)
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && e.ctrlKey) {
					this.save();
				}
			});
		}

		initSortable() {
			// Create a flat list of all items for Sortable
			Sortable.create(this.listEl, {
				group: this.taxonomy,
				animation: 150,
				ghostClass: 'counter-taxonomy-item--dragging',
				dragClass: 'counter-taxonomy-item--drag',
				handle: '.counter-taxonomy-item__drag-handle',
				onEnd: () => {
					// Mark as dirty when order changes
					this.markDirty();
				},
			});

			// Also make nested lists sortable (for hierarchies)
			const childLists = document.querySelectorAll('.counter-taxonomy-item__children');
			childLists.forEach((list) => {
				Sortable.create(list, {
					group: this.taxonomy,
					animation: 150,
					ghostClass: 'counter-taxonomy-item--dragging',
					dragClass: 'counter-taxonomy-item--drag',
					handle: '.counter-taxonomy-item__drag-handle',
					onEnd: () => this.markDirty(),
				});
			});
		}

		markDirty() {
			this.saveBtn.classList.add('button-primary');
			this.saveBtn.textContent = 'Save Order (unsaved changes)';
		}

		getOrder() {
			const updates = {};
			let position = 0;

			// Walk all root items
			const items = this.listEl.querySelectorAll(':scope > .counter-taxonomy-item');
			items.forEach((item) => {
				const termId = parseInt(item.dataset.termId, 10);
				updates[termId] = {
					position: position++,
					parent_id: null,
				};

				// Walk children
				const children = item.querySelectorAll('.counter-taxonomy-item__children > .counter-taxonomy-item');
				let childPos = 0;
				children.forEach((child) => {
					const childId = parseInt(child.dataset.termId, 10);
					updates[childId] = {
						position: childPos++,
						parent_id: termId,
					};
				});
			});

			return updates;
		}

		async save() {
			const updates = this.getOrder();

			this.spinner.style.display = 'inline-block';
			this.message.textContent = '';
			this.message.className = 'counter-order-message';

			try {
				const response = await fetch(
					`${this.restUrl}counter/v1/admin/taxonomies/${this.taxonomy}`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': this.nonce,
						},
						body: JSON.stringify({ updates }),
					}
				);

				if (!response.ok) {
					const error = await response.json();
					throw new Error(error.message || 'Failed to save order');
				}

				this.message.textContent = 'Order saved successfully!';
				this.message.classList.add('counter-order-message--success');
				this.saveBtn.textContent = 'Save Order';
				this.saveBtn.classList.remove('button-primary');

				// Clear message after 3 seconds
				setTimeout(() => {
					this.message.textContent = '';
				}, 3000);
			} catch (error) {
				this.message.textContent = `Error: ${error.message}`;
				this.message.classList.add('counter-order-message--error');
				console.error('Failed to save taxonomy order:', error);
			} finally {
				this.spinner.style.display = 'none';
			}
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => new TaxonomyOrderer());
	} else {
		new TaxonomyOrderer();
	}
})();
