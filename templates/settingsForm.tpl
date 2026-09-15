{**
 * plugins/generic/controlledVocabSplitter/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Which vocabularies are split, and which separators are honoured.
 *
 * The checkboxes are written by hand on purpose: {fbvElement type="checkbox"}
 * renders a bare <li> (lib/pkp/templates/form/checkbox.tpl) that is only valid
 * inside {fbvFormSection list=true}, and a table would throw it out.
 *}
<script>
	$(function() {ldelim}
		$('#controlledVocabSplitterSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<link rel="stylesheet" href="{$cvsStyleUrl|escape}">

<form
	class="pkp_form"
	id="controlledVocabSplitterSettingsForm"
	method="post"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="controlledVocabSplitterSettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.controlledVocabSplitter.settings.description"}</div>

	{fbvFormArea id="controlledVocabSplitterArea"}
		<p class="cvsHeading">{translate key="plugins.generic.controlledVocabSplitter.settings.fields.title"}</p>
		<p class="cvsHint">{translate key="plugins.generic.controlledVocabSplitter.settings.fields.hint"}</p>

		<table class="cvsTable">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.controlledVocabSplitter.settings.column.vocabulary"}</th>
					<th class="cvsCheck">{translate key="plugins.generic.controlledVocabSplitter.settings.column.split"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$fieldRows item=row}
					<tr>
						<td>
							<span class="cvsName">{translate key=$row.label}</span>
							<span class="cvsExample"><code>{$row.name|escape}</code></span>
						</td>
						<td class="cvsCheck">
							<input
								type="checkbox"
								id="cvsField-{$row.name|escape}"
								name="fields[]"
								value="{$row.name|escape}"
								{if $row.checked}checked="checked"{/if}
							/>
							<label class="pkp_screen_reader" for="cvsField-{$row.name|escape}">{translate key=$row.label}</label>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>

		<p class="cvsHeading">{translate key="plugins.generic.controlledVocabSplitter.settings.separators.title"}</p>
		<p class="cvsHint">{translate key="plugins.generic.controlledVocabSplitter.settings.separators.hint"}</p>

		<table class="cvsTable">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.controlledVocabSplitter.settings.column.separator"}</th>
					<th class="cvsCheck">{translate key="plugins.generic.controlledVocabSplitter.settings.column.use"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$separatorRows item=row}
					<tr>
						<td>
							<span class="cvsName">{translate key=$row.label}</span>
							<span class="cvsExample">{translate key=$row.example}</span>
						</td>
						<td class="cvsCheck">
							<input
								type="checkbox"
								id="cvsSeparator-{$row.name|escape}"
								name="separators[]"
								value="{$row.name|escape}"
								{if $row.checked}checked="checked"{/if}
							/>
							<label class="pkp_screen_reader" for="cvsSeparator-{$row.name|escape}">{translate key=$row.label}</label>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>

		<div class="cvsNotice">
			<strong>{translate key="plugins.generic.controlledVocabSplitter.settings.comma.title"}</strong><br />
			{translate key="plugins.generic.controlledVocabSplitter.settings.comma.body"}
		</div>

		<div class="cvsNotice cvsNotice--info">
			<strong>{translate key="plugins.generic.controlledVocabSplitter.settings.safety.title"}</strong><br />
			{translate key="plugins.generic.controlledVocabSplitter.settings.safety.body"}
		</div>

		<p class="cvsHint">{translate key="plugins.generic.controlledVocabSplitter.settings.hint"}</p>
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
