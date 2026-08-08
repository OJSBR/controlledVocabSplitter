/**
 * @file cypress/tests/functional/ControlledVocabSplitter.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 */

describe('Controlled Vocabulary Splitter plugin tests', function() {
	const settingsForm = 'form[id="controlledVocabSplitterSettingsForm"]';

	const openPluginSettings = () => {
		cy.get('button[id="plugins-button"]').click();
		cy.get('tr[id*="controlledvocabsplitterplugin"] a.show_extras').click();
		cy.get('a[id*="controlledvocabsplitterplugin-settings"]').click();
		cy.waitJQuery();
	};

	it('Turns a separator off and persists the settings', function() {
		cy.login('admin', 'admin', 'publicknowledge');

		cy.get('nav').contains('Settings').click();
		// Ensure submenu item click despite animation
		cy.get('nav').contains('Website').click({ force: true });
		cy.get('button[id="plugins-button"]').click();

		// Find and enable the plugin
		cy.get('input[id^="select-cell-controlledvocabsplitterplugin-enabled"]').click();
		cy.contains('has been enabled');
		cy.waitJQuery();

		// Open the plugin settings
		openPluginSettings();

		// Everything is split by every separator out of the box
		cy.get(settingsForm + ' input[id="cvsField-keywords"]').should('be.checked');
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').should('be.checked');

		// The comma is the risky separator: turn it off and save
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').uncheck({ force: true });
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({ force: true });
		cy.waitJQuery();

		// Reopen the settings: the change must have been persisted
		cy.reload();
		cy.waitJQuery();
		openPluginSettings();
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').should('not.be.checked');
		cy.get(settingsForm + ' input[id="cvsSeparator-semicolon"]').should('be.checked');

		// Restore the defaults so the test is repeatable
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').check({ force: true });
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({ force: true });
		cy.waitJQuery();
	});
})
