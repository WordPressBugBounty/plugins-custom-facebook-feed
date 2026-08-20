/**
 * Admin popup focus trap (SMASH-1378 / A11Y-004).
 *
 * Watches for [role="dialog"] elements appearing in the DOM -- the CFF builder
 * admin popups declared as dialogs (ported from IG Pro PR #1480) AND the
 * cross-plugin "install this plugin" modal that the CFF admin submenu items
 * (Instagram / Reviews / YouTube / Twitter Feed) inject via AJAX into
 * #cff-op-modals on the CFF admin pages -- and:
 *  - saves the element that had focus before the popup opened
 *  - moves focus to the first focusable element inside the popup on open
 *  - traps Tab / Shift+Tab within the popup
 *  - restores focus to the trigger element on close
 *
 * Pure vanilla JS -- no jQuery dependency. The popups are toggled via Vue's
 * v-if, so we can't hook into a single Vue lifecycle; instead we observe
 * DOM mutations on document.body, which fires whenever Vue adds/removes
 * a popup container.
 */
(function () {
	'use strict';

	// Selector for focusable elements -- the same pattern used in the
	// frontend lightbox focus trap (js/sb-instagram.js sbiFocusTrap).
	var FOCUSABLE_SELECTOR = [
		'button:not([disabled]):not([tabindex="-1"])',
		'a[href]:not([tabindex="-1"])',
		'input:not([disabled]):not([type="hidden"]):not([tabindex="-1"])',
		'select:not([disabled]):not([tabindex="-1"])',
		'textarea:not([disabled]):not([tabindex="-1"])',
		'[tabindex]:not([tabindex="-1"])'
	].join(', ');

	// Map dialog element -> the element that had focus when it opened.
	// Keys are weakly referenced via WeakMap so we don't leak when Vue
	// destroys the popup nodes.
	var triggerMap = new WeakMap();

	// Currently focused dialog (the topmost / most recently opened).
	var activeDialog = null;

	function getVisibleFocusables(container) {
		var nodes = container.querySelectorAll(FOCUSABLE_SELECTOR);
		var visible = [];
		for (var i = 0; i < nodes.length; i++) {
			var el = nodes[i];
			// offsetParent is null for display:none ancestors -- skip those.
			// Edge case: position:fixed elements also have null offsetParent
			// even when visible, so fall back to getClientRects() (mirrors the
			// same check in isDialogVisible). Currently-focused elements are
			// always kept regardless of computed visibility, since blurring
			// the focus mid-trap would break the cycle.
			if (el.offsetParent === null && el !== document.activeElement) {
				if (el.getClientRects().length === 0) continue;
			}
			visible.push(el);
		}
		return visible;
	}

	function isDialogVisible(dialog) {
		if (!dialog || !dialog.isConnected) return false;
		// Vue's v-if removes from DOM entirely, but some popups use v-show
		// (display:none). offsetParent catches both.
		if (dialog.offsetParent === null) {
			// Edge case: position:fixed dialogs have null offsetParent even
			// when visible. Fall back to checking getClientRects.
			return dialog.getClientRects().length > 0;
		}
		return true;
	}

	// Only trap CFF-owned dialogs. This script is enqueued on CFF admin
	// screens but the browser shares one document with whatever else is on
	// the page, so a sibling Smash plugin's builder (e.g. the TikTok/Reviews
	// React customizer) can render its own [role="dialog"] nodes — including
	// closed-but-present popups. Without this guard our MutationObserver +
	// Tab trap would latch onto those foreign dialogs and trap focus inside
	// (or out of) a panel we don't own. CFF dialogs are identifiable: the
	// cross-plugin install modal mounts inside #cff-op-modals, and every CFF
	// builder popup ships a .cff-fb-popup-cls (or [data-cff-dialog-close])
	// close affordance.
	function isCffOwnedDialog(dialog) {
		if (!dialog || dialog.nodeType !== 1) return false;
		if (dialog.closest && dialog.closest('#cff-op-modals')) return true;
		if (dialog.querySelector && (dialog.querySelector('.cff-fb-popup-cls') || dialog.querySelector('[data-cff-dialog-close]'))) return true;
		return false;
	}

	function onDialogOpen(dialog) {
		if (!isCffOwnedDialog(dialog)) return;
		// Save trigger (whatever had focus just before this popup mounted).
		// document.activeElement at MutationObserver-time is the most
		// reliable "what was focused before Vue inserted this" we can get.
		var trigger = document.activeElement;
		if (trigger && trigger !== document.body) {
			triggerMap.set(dialog, trigger);
		}
		activeDialog = dialog;

		// Focus the first focusable element inside the dialog. Defer one
		// frame so Vue has finished rendering child nodes -- v-if can mount
		// the wrapper before its descendants in some edge cases.
		requestAnimationFrame(function () {
			var focusables = getVisibleFocusables(dialog);
			if (!focusables.length) return;
			try { focusables[0].focus(); } catch (e) { /* element gone */ }
		});
	}

	function onDialogClose(dialog) {
		if (activeDialog === dialog) activeDialog = null;
		var trigger = triggerMap.get(dialog);
		triggerMap.delete(dialog);
		if (trigger && document.body.contains(trigger)) {
			try { trigger.focus(); } catch (e) { /* trigger gone */ }
		}
	}

	// Resolve the topmost visible [role="dialog"] -- a click in the
	// customizer can open a second-level popup over the first, so Tab/Esc
	// should always target the innermost / most recently opened dialog.
	function getTopmostVisibleDialog() {
		var dialogs = document.querySelectorAll('[role="dialog"]');
		for (var i = dialogs.length - 1; i >= 0; i--) {
			if (isCffOwnedDialog(dialogs[i]) && isDialogVisible(dialogs[i])) return dialogs[i];
		}
		return null;
	}

	function trapKeydown(e) {
		var isTab = e.key === 'Tab' || e.keyCode === 9;
		if (!isTab) return;
		var dialog = getTopmostVisibleDialog();
		if (!dialog) return;

		var focusables = getVisibleFocusables(dialog);
		if (!focusables.length) return;
		var first = focusables[0];
		var last = focusables[focusables.length - 1];
		var active = document.activeElement;
		var insideDialog = dialog.contains(active);

		if (e.shiftKey) {
			if (!insideDialog || active === first) {
				e.preventDefault();
				try { last.focus(); } catch (err) {}
			}
		} else {
			if (!insideDialog || active === last) {
				e.preventDefault();
				try { first.focus(); } catch (err) {}
			}
		}
	}

	// Esc-to-close. Every builder popup ships a `.cff-fb-popup-cls` close
	// button wired to the Vue method that toggles the dialog's
	// v-if off. Synthesizing a click on that button -- rather than emitting
	// a Vue event or mutating reactive state -- keeps this script
	// framework-agnostic and reuses each popup's existing close path
	// (which already handles cleanup like resetting form state, etc.).
	// Focus restoration happens via the MutationObserver-driven
	// onDialogClose when Vue unmounts the dialog node.
	function escKeydown(e) {
		var isEsc = e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27;
		if (!isEsc) return;
		var dialog = getTopmostVisibleDialog();
		if (!dialog) return;
		// Prefer the canonical X button so we hit each popup's bespoke
		// close handler. Fall back to any [data-cff-dialog-close] hook
		// for future popups that don't follow the .cff-fb-popup-cls
		// convention, then to the dialog's own close-button aria-label.
		var closeBtn = dialog.querySelector('.cff-fb-popup-cls')
			|| dialog.querySelector('[data-cff-dialog-close]')
			|| dialog.querySelector('button[aria-label*="Close" i]');
		if (!closeBtn) return;
		e.preventDefault();
		e.stopPropagation();
		try { closeBtn.click(); } catch (err) { /* button gone */ }
	}

	// Single document-level keydown listener (capture phase so we run
	// before Vue's internal handlers).
	document.addEventListener('keydown', trapKeydown, true);
	document.addEventListener('keydown', escKeydown, true);

	// Observe popups being inserted / removed by Vue's v-if.
	var observer = new MutationObserver(function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			var m = mutations[i];
			// Added nodes -- check if any are (or contain) a [role="dialog"].
			for (var a = 0; a < m.addedNodes.length; a++) {
				var added = m.addedNodes[a];
				if (added.nodeType !== 1) continue;
				if (added.getAttribute && added.getAttribute('role') === 'dialog') {
					onDialogOpen(added);
				} else if (added.querySelectorAll) {
					var nested = added.querySelectorAll('[role="dialog"]');
					for (var n = 0; n < nested.length; n++) onDialogOpen(nested[n]);
				}
			}
			// Removed nodes -- restore focus if a dialog disappeared.
			for (var r = 0; r < m.removedNodes.length; r++) {
				var removed = m.removedNodes[r];
				if (removed.nodeType !== 1) continue;
				if (removed.getAttribute && removed.getAttribute('role') === 'dialog') {
					onDialogClose(removed);
				} else if (removed.querySelectorAll) {
					var nestedR = removed.querySelectorAll('[role="dialog"]');
					for (var nr = 0; nr < nestedR.length; nr++) onDialogClose(nestedR[nr]);
				}
			}
		}
	});

	function startObserving() {
		observer.observe(document.body, { childList: true, subtree: true });

		// Pick up any dialogs already in the DOM at script-load time
		// (defensive -- unlikely but possible if the builder mounts before
		// this script runs).
		var existing = document.querySelectorAll('[role="dialog"]');
		for (var i = 0; i < existing.length; i++) {
			if (isDialogVisible(existing[i])) onDialogOpen(existing[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startObserving);
	} else {
		startObserving();
	}
})();
