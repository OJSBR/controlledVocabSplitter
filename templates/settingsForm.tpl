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

<style>
	.cvsTable {ldelim} width:100%; border-collapse:collapse; margin:.4em 0 1.2em; {rdelim}
	.cvsTable th, .cvsTable td {ldelim} padding:.7em .9em; border-bottom:1px solid #e3e9ef; text-align:left; vertical-align:top; {rdelim}
	.cvsTable thead th {ldelim} background:#f4f7fb; color:#33414e; font-size:.92em; {rdelim}
	.cvsTable tbody tr:hover {ldelim} background:#fafbfc; {rdelim}
	.cvsTable td.cvsCheck, .cvsTable th.cvsCheck {ldelim} text-align:center; width:6em; {rdelim}
	.cvsTable input[type="checkbox"] {ldelim} width:16px; height:16px; margin:0; cursor:pointer; {rdelim}
	.cvsName {ldelim} font-weight:700; color:#16232f; {rdelim}
	.cvsExample {ldelim} display:block; font-size:.85em; color:#61707e; margin-top:.25em; {rdelim}
	.cvsExample code {ldelim} background:#f2f5f8; padding:.1em .35em; border-radius:3px; {rdelim}
	.cvsHeading {ldelim} margin:1.4em 0 .3em; font-size:1.05em; font-weight:700; color:#16232f; {rdelim}
	.cvsNotice {ldelim}
		margin:1em 0; padding:.9em 1.1em; border:1px solid #e6cf6a; border-left:4px solid #d8b520;
		background:#fffbe9; border-radius:6px; line-height:1.5;
	{rdelim}
	.cvsNotice strong {ldelim} color:#6b5600; {rdelim}
	.cvsNotice--info {ldelim} border-color:#b9d3e6; border-left-color:#3a6ea5; background:#f2f7fb; {rdelim}
	.cvsNotice--info strong {ldelim} color:#20486e; {rdelim}
	.cvsHint {ldelim} color:#61707e; margin:.6em 0 0; font-size:.93em; line-height:1.5; {rdelim}
</style>

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
