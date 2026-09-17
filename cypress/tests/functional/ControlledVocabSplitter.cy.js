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
		waitJQuery();
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
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(settingsForm).data('pkp.handler')).to.exist;
		});
	};

	const save = () => {
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	// ---- OJSBR spec helpers (padrão v2) ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which a run without their support file lacks.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: a session whose agent changes is dropped.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// REST calls made from the page itself, so they carry its session and token.
	const api = (path) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {credentials: 'same-origin'}).then((response) => response.json()),
		{log: false, timeout: 30000}
	));

	const send = (path, method, body) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {
			method: method,
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: body === undefined ? undefined : JSON.stringify(body),
		}).then((response) => response.json().then((answer) => ({status: response.status, body: answer}))),
		{log: false, timeout: 60000}
	));

	// Submissions made by the tests, deleted in after() even when one fails.
	const madeHere = [];

	// Creates a submission of its own, so the test depends on no data set.
	const aSubmission = (locale) => request({url: pageUrl('api/v1/sections?count=1'), failOnStatusCode: false})
		.then((response) => {
			let body = response.body;
			if (typeof body === 'string') {
				try {
					body = JSON.parse(body);
				} catch (error) {
					body = {};
				}
			}

			return (body && body.items && body.items.length) ? body.items[0].id : null;
		})
		.then((sectionId) => send(pageUrl('api/v1/submissions'), 'POST', sectionId ? {locale: locale, sectionId: sectionId} : {locale: locale}))
		.then((created) => {
			expect(created.status, 'the submission of the test was created: ' + JSON.stringify(created.body)).to.be.within(200, 201);
			madeHere.push(created.body.id);

			return cy.wrap(created.body, {log: false});
		});

	it('Enables the plugin', function() {
		login();
		cy.visit('/index.php/' + contextPath + '/management/settings/website?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).should('have.attr', 'aria-selected', 'true');
		waitJQuery();
		cy.get('input[id^="select-cell-controlledvocabsplitterplugin-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
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

	// The promise of the plugin: a line of terms typed as one is stored as several.
	// The submission is made by the test, the line is written through the endpoint
	// the metadata form uses, and the terms are then read back from the server.
	it('Splits a line of keywords saved through the API and keeps them split', function() {
		const line = 'Palatal Expansion. Clinical Protocol. Orthopedic appliance.';
		const terms = ['Palatal Expansion', 'Clinical Protocol', 'Orthopedic appliance'];

		login();
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.window({log: false}).its('pkp.context.primaryLocale').then((locale) => {
			aSubmission(locale).then((submission) => {
				const publication = pageUrl('api/v1/submissions/' + submission.id + '/publications/' + submission.currentPublicationId);

				send(publication, 'PUT', {keywords: {[locale]: [line]}}).then((saved) => {
					expect(saved.status, 'the keywords were saved: ' + JSON.stringify(saved.body)).to.eq(200);

					return api(publication);
				}).then((stored) => {
					// A term comes back as a string or as an object with its name,
					// depending on the line of the application.
					const kept = ((stored.keywords || {})[locale] || [])
						.map((term) => (term && typeof term === 'object' ? term.name : term));
					terms.forEach((term) => {
						expect(kept, 'the term "' + term + '" was not stored on its own: ' + JSON.stringify(kept)).to.include(term);
					});
					expect(kept, 'the line was kept whole as well').to.not.include(line);
				});
			});
		});
	});

	after(function() {
		if (!madeHere.length) {
			return;
		}
		login();
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		madeHere.forEach((id) => send(pageUrl('api/v1/submissions/' + id), 'DELETE'));
	});
});
