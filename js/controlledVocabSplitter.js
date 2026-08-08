/**
 * @file plugins/generic/controlledVocabSplitter/js/controlledVocabSplitter.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Makes the controlled-vocabulary field split a pasted list into separate tags.
 *
 * The rules below are a mirror of ControlledVocabSplitter.php, deliberately kept
 * dumb and explicit so the two can be compared term by term; tests/CASOS.md
 * documents how the parity is verified. The server applies the same rules on
 * every write, so this file is what the author sees, never what guarantees the
 * data.
 */
(function () {
	'use strict';

	var config = window.ojsbrControlledVocabSplitter || {};
	var separators = config.separators || ['semicolon', 'comma', 'period'];
	var fields = config.fields || ['keywords', 'subjects', 'disciplines', 'supportingAgencies'];

	var EXOTIC_SPACES = /[\u00A0\u1680\u2000-\u200A\u202F\u205F\u3000]/g;
	var ZERO_WIDTH = /[\u200B\u200C\u200D\uFEFF]/g;
	var LEADING_JUNK = /^[\s:;,.\u2012\u2013\u2014\u2015-]+/;
	var TRAILING_JUNK = /[\s;,.]+$/;
	var LETTER = /\p{L}/u;
	var LETTER_OR_DIGIT = /[\p{L}\p{N}]/u;

	/**
	 * Clean one term: normalise spacing and Unicode, then drop the punctuation
	 * left over from the separator itself.
	 */
	function normalize(value) {
		value = String(value === null || value === undefined ? '' : value);
		value = value.replace(EXOTIC_SPACES, ' ').replace(ZERO_WIDTH, '');
		if (typeof value.normalize === 'function') {
			value = value.normalize('NFC');
		}
		value = value.replace(/\s+/g, ' ').trim();
		value = value.replace(LEADING_JUNK, '').replace(TRAILING_JUNK, '');

		return value.trim();
	}

	function has(separator) {
		return separators.indexOf(separator) !== -1;
	}

	function isSpace(char) {
		return char === ' ' || char === '\t' || char === '\n' || char === '\r';
	}

	/**
	 * A period separates only when a space follows it — that is what keeps
	 * "Lei 13.964/2019" whole — and never when it closes a single letter, which
	 * is what keeps "S. aureus" and "E. coli" whole.
	 */
	function splitOnPeriod(value) {
		var chars = Array.from(value);
		var parts = [];
		var current = '';

		for (var i = 0; i < chars.length; i++) {
			var char = chars[i];

			if (char !== '.' || i + 1 >= chars.length || !isSpace(chars[i + 1])) {
				current += char;
				continue;
			}

			var previous = i > 0 ? chars[i - 1] : '';
			var beforePrevious = i > 1 ? chars[i - 2] : '';

			if (LETTER.test(previous) && (i < 2 || !LETTER_OR_DIGIT.test(beforePrevious))) {
				current += char;
				continue;
			}

			parts.push(current);
			current = '';
			i++;
		}

		parts.push(current);

		return parts;
	}

	/**
	 * Split one raw string into the terms it holds. Only one separator is used
	 * per string: the first of semicolon, comma, period that occurs in it.
	 */
	function split(raw) {
		var value = normalize(raw);
		if (value === '') {
			return [];
		}

		var parts = null;

		if (has('semicolon') && value.indexOf(';') !== -1) {
			parts = value.split(/\s*;\s*/);
		} else if (has('comma') && /,\s/.test(value)) {
			parts = value.split(/\s*,\s+/);
		} else if (has('period')) {
			parts = splitOnPeriod(value);
		}

		if (parts === null || parts.length < 2) {
			return [value];
		}

		var terms = [];
		var seen = {};
		parts.forEach(function (part) {
			part = normalize(part);
			if (part === '') {
				return;
			}
			var key = part.toLowerCase();
			if (seen[key]) {
				return;
			}
			seen[key] = true;
			terms.push(part);
		});

		return terms.length ? terms : [value];
	}

	// Exposed so the regression suite can run the PHP fixture through this very
	// code in a real browser, which is the only way to prove the two agree.
	window.ojsbrControlledVocabSplitterRules = {normalize: normalize, split: split};

	if (!window.pkp || !window.pkp.registry || typeof pkp.registry.getAllComponents !== 'function') {
		return;
	}

	/**
	 * Wraps the core field component, keeping everything it does and adding the
	 * split. Marked so the same component is never wrapped twice.
	 */
	function wrap(base) {
		if (!base || typeof base !== 'object' || base.ojsbrSplitter) {
			return base;
		}
		if (!base.methods || typeof base.methods.selectSuggestion !== 'function') {
			return base; // not the component this plugin knows: leave it alone
		}

		return {
			name: 'FieldControlledVocabSplitter',
			extends: base,
			ojsbrSplitter: true,
			mounted: function () {
				var self = this;
				var input = this.$el ? this.$el.querySelector('input.pkpAutosuggest__input') : null;
				if (!input || !this.appliesToField()) {
					return;
				}

				this.ojsbrPasteHandler = function (event) {
					var clipboard = event.clipboardData || window.clipboardData;
					if (!clipboard) {
						return;
					}

					var pasted = clipboard.getData('text');
					if (!pasted) {
						return;
					}

					// Whatever was already typed belongs to the same term the author
					// is writing, so it is spliced in at the caret before splitting.
					var existing = input.value || '';
					var start = typeof input.selectionStart === 'number' ? input.selectionStart : existing.length;
					var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : existing.length;
					var terms = split(existing.slice(0, start) + pasted + existing.slice(end));

					if (terms.length < 2) {
						return; // nothing to split: let the browser paste as usual
					}

					event.preventDefault();
					self.addTerms(terms);

					// The visible text is owned by the combobox, not by the field, so
					// it is cleared the same way a person would: by an input event.
					input.value = '';
					input.dispatchEvent(new Event('input', {bubbles: true}));
				};

				input.addEventListener('paste', this.ojsbrPasteHandler);
				this.ojsbrInput = input;
			},
			beforeUnmount: function () {
				if (this.ojsbrInput && this.ojsbrPasteHandler) {
					this.ojsbrInput.removeEventListener('paste', this.ojsbrPasteHandler);
				}
			},
			methods: {
				/**
				 * Only the vocabularies the journal asked for. The same component
				 * renders other fields (user interests, for one) and they are none of
				 * this plugin's business.
				 */
				appliesToField: function () {
					return fields.indexOf(this.name) !== -1;
				},

				/**
				 * Adding the terms in one go is not an optimisation: `currentSelected`
				 * only refreshes after the form store has taken the change, so adding
				 * them one by one would keep the last term and drop the rest.
				 */
				addTerms: function (terms) {
					var current = (this.currentSelected || []).slice();
					var seen = {};

					current.forEach(function (item) {
						seen[String(item && item.label ? item.label : '').toLowerCase()] = true;
					});

					var additions = [];
					terms.forEach(function (term) {
						var key = term.toLowerCase();
						if (!key || seen[key]) {
							return;
						}
						seen[key] = true;
						additions.push({value: {name: term}, label: term});
					});

					if (additions.length) {
						this.setSelected(current.concat(additions));
					}
					this.inputValue = '';
				},

				/**
				 * Enter, or a click on the "add this" entry of the suggestion list.
				 * A suggestion picked from the list is a real vocabulary entry and is
				 * never touched; only free text typed by the author is split.
				 */
				selectSuggestion: function (suggestion) {
					if (suggestion || !this.inputValue || !this.appliesToField()) {
						return base.methods.selectSuggestion.call(this, suggestion);
					}

					var terms = split(this.inputValue);
					if (terms.length < 2) {
						return base.methods.selectSuggestion.call(this, suggestion);
					}

					this.addTerms(terms);
				}
			}
		};
	}

	/**
	 * The form fields are not resolved from the global registry: FormGroup keeps
	 * its own `components` map, and that is the copy Vue actually renders. So the
	 * component tree is walked from every globally registered component and every
	 * FieldControlledVocab found in it is replaced — global registration included,
	 * for whatever resolves by name.
	 */
	var visited = new Set();

	function patch(options) {
		if (!options || typeof options !== 'object' || visited.has(options)) {
			return;
		}
		visited.add(options);

		var components = options.components;
		if (components && typeof components === 'object') {
			if (components.FieldControlledVocab) {
				components.FieldControlledVocab = wrap(components.FieldControlledVocab);
			}
			Object.keys(components).forEach(function (key) {
				patch(components[key]);
			});
		}

		patch(options.extends);
		(options.mixins || []).forEach(patch);
	}

	var registry = pkp.registry.getAllComponents();
	Object.keys(registry).forEach(function (name) {
		if (name === 'PkpFieldControlledVocab') {
			registry[name] = wrap(registry[name]);
		}
		patch(registry[name]);
	});
})();
