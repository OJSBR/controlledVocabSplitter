/**
 * @file cypress/tests/functional/ControlledVocabSplitter.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings persist, and a keyword line entered as one
 * term in the metadata form is saved as separate terms.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (a journal manager)
 * and submissionId (a submission whose metadata can be edited; the metadata test
 * is skipped without it). Captcha on login must be off for the run. The defaults
 * match the data set of PKP's continuous integration, and the first test enables
 * the plugin when it is off. Selectors use ids and names, so the spec runs against a
 * journal in any language. The settings touched are put back; the metadata test
 * saves keywords to submissionId, so point it at a test submission.
 */

describe('Controlled Vocabulary Splitter plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const submissionId = Cypress.env('submissionId');
	const settingsForm = 'form[id="controlledVocabSplitterSettingsForm"]';

	// Signs in through requests: the login page can re-render while it is typed into.
	const login = () => {
		cy.clearCookies();
		cy.request('/index.php/' + contextPath + '/login').then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			cy.request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: adminUser, password: adminPassword}, log: false});
		});
	};

	const openPluginsTab = () => {
		// A new query string forces a page load; the hash opens the Plugins tab.
		cy.visit('/index.php/' + contextPath + '/management/settings/website?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).should('have.attr', 'aria-selected', 'true');
		cy.waitJQuery();
	};

	// Opens the settings modal from the plugins grid. The form is fetched from the
	// server each time, so reopening it without reloading the page still proves
	// what was stored (a full reload right after saving can stall CI's web server).
	const openSettings = () => {
		cy.get('a[id*="controlledvocabsplitterplugin-settings"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id*="controlledvocabsplitterplugin"] a.show_extras').click();
			}
		});
		cy.get('a[id*="controlledvocabsplitterplugin-settings"]').should('be.visible').click();
		cy.waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(settingsForm).data('pkp.handler')).to.exist;
		});
	};

	const save = () => {
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	it('Enables the plugin', function() {
		login();
		cy.visit('/index.php/' + contextPath + '/management/settings/website?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).should('have.attr', 'aria-selected', 'true');
		cy.waitJQuery();
		cy.get('input[id^="select-cell-controlledvocabsplitterplugin-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				cy.waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-controlledvocabsplitterplugin-enabled"]').should('be.checked');
	});

	it('Turns a separator off, keeps it off, and puts it back', function() {
		login();
		openPluginsTab();
		openSettings();
		cy.get('link[href*="/controlledVocabSplitter/css/settings.css"]').should('have.length', 1);
		cy.get(settingsForm + ' input[id="cvsField-keywords"]').should('be.checked');
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').should('be.checked').uncheck({force: true});
		save();

		openSettings();
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').should('not.be.checked');
		cy.get(settingsForm + ' input[id="cvsSeparator-semicolon"]').should('be.checked');
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').check({force: true});
		save();

		openSettings();
		cy.get(settingsForm + ' input[id="cvsSeparator-comma"]').should('be.checked');
	});

	// Defined only with a submission to edit: this.skip() inside a test breaks the
	// failed-log hook of PKP's Cypress support.
	(submissionId ? it : it.skip)('Saves a keyword line entered as one term as separate terms', function() {
		const line = 'Palatal Expansion. Clinical Protocol. Orthopedic appliance.';
		const keywords = 'input[id^="metadata-keywords-control"]';
		const field = () => cy.get(keywords).first().closest('.pkpFormField');
		const openMetadata = () => {
			cy.visit('/index.php/' + contextPath + '/dashboard/editorial?workflowSubmissionId=' + submissionId + '&workflowMenuKey=publication_metadata&reload=' + Date.now());
			cy.get(keywords, {timeout: 60000}).first().scrollIntoView().should('be.visible');
		};
		const save = () => {
			cy.intercept('**/api/v1/submissions/*/publications/*').as('savePublication');
			cy.get(keywords).first().closest('form').find('button[type="submit"], .pkpFormPage__footer button').last().click();
			cy.wait('@savePublication').its('response.statusCode').should('eq', 200);
		};

		login();
		openMetadata();
		// The custom term can only be added once the suggestions for it are back.
		cy.intercept('**/api/v1/vocabs*').as('suggestions');
		cy.get(keywords).first().type(line, {delay: 0});
		cy.wait('@suggestions');
		cy.get(keywords).first().type('{enter}');
		field().contains(line).should('exist');
		save();

		openMetadata();
		['Palatal Expansion', 'Clinical Protocol', 'Orthopedic appliance'].forEach((term) => {
			field().contains(term).should('exist');
		});
		field().contains('Palatal Expansion. Clinical Protocol').should('not.exist');
	});
});
