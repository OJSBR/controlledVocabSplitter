/**
 * @file cypress/tests/functional/ControlledVocabSplitter.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings persist, and a keyword list pasted into the
 * metadata form becomes separate terms.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (a journal manager)
 * and submissionId (a submission whose metadata can be edited; the paste and
 * parity tests are skipped without it). The parity test also needs
 * tests/cases.json, written by tests/regression.php on the same site. Captcha on login must be off for the run. The plugin
 * must be enabled. Selectors use ids and names, so the spec runs against a
 * journal in any language. The settings touched are put back; the pasted terms
 * are never saved.
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

	const openSettings = () => {
		// A new query string forces a page load; the hash opens the Plugins tab.
		cy.visit('/index.php/' + contextPath + '/management/settings/website?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).should('have.attr', 'aria-selected', 'true');
		cy.waitJQuery();
		cy.get('tr[id*="controlledvocabsplitterplugin"] a.show_extras', {timeout: 30000}).should('be.visible').click();
		cy.get('a[id*="controlledvocabsplitterplugin-settings"]', {timeout: 30000}).click();
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

	it('Turns a separator off, keeps it off, and puts it back', function() {
		login();
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

	it('Splits a keyword line pasted into the metadata form', function() {
		if (!submissionId) {
			this.skip();
		}
		login();
		cy.visit('/index.php/' + contextPath + '/dashboard/editorial?workflowSubmissionId=' + submissionId + '&workflowMenuKey=publication_metadata');
		cy.get('input[id^="metadata-keywords-control"]', {timeout: 60000}).first().scrollIntoView().should('be.visible').then(($input) => {
			const data = new DataTransfer();
			data.setData('text', 'Palatal Expansion. Clinical Protocol. Orthopedic appliance.');
			$input[0].focus();
			$input[0].dispatchEvent(new ClipboardEvent('paste', {clipboardData: data, bubbles: true, cancelable: true}));
		});
		['Palatal Expansion', 'Clinical Protocol', 'Orthopedic appliance'].forEach((term) => {
			cy.get('input[id^="metadata-keywords-control"]').first().closest('.pkpFormField')
				.contains(term).should('exist');
		});
		cy.get('input[id^="metadata-keywords-control"]').first().closest('.pkpFormField')
			.contains('Palatal Expansion. Clinical Protocol').should('not.exist');
	});

	it('Applies in the browser exactly the rules the server applies', function() {
		if (!submissionId) {
			this.skip();
		}
		cy.request({url: '/plugins/generic/controlledVocabSplitter/tests/cases.json', failOnStatusCode: false}).then((response) => {
			if (response.status !== 200) {
				this.skip();
			}
			login();
			cy.visit('/index.php/' + contextPath + '/dashboard/editorial?workflowSubmissionId=' + submissionId + '&workflowMenuKey=publication_metadata');
			cy.window({timeout: 60000}).its('ojsbrControlledVocabSplitterRules').then((rules) => {
				const all = ['semicolon', 'comma', 'period'];
				const cases = response.body;
				const normalize = cases.normalize.filter((c) => rules.normalize(c.in) !== c.out).map((c) => c.id);
				const split = cases.split
					.filter((c) => JSON.stringify(c.separators) === JSON.stringify(all))
					.filter((c) => JSON.stringify(rules.split(c.in)) !== JSON.stringify(c.out)).map((c) => c.id);
				expect(cases.normalize.length).to.be.greaterThan(0);
				expect(normalize, 'normalize cases that differ from PHP').to.deep.equal([]);
				expect(split, 'split cases that differ from PHP').to.deep.equal([]);
			});
		});
	});
});
