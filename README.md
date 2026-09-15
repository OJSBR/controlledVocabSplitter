# Controlled Vocabulary Splitter — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.1.1-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/controlledVocabSplitter/releases/download/1.0.1.1/controlledVocabSplitter-1.0.1.1.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that splits **keywords, subjects,
disciplines and supporting agencies pasted as a single line** into the separate terms the
author meant, whenever a publication is saved or imported — through core hooks only, **without
patching or replacing anything in OJS core**.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.1 |

> **Upgrade from 1.0.0.x.** Earlier versions replaced a core class (the controlled-vocabulary
> repository) and changed the compiled vocabulary field so a pasted list was split while it was
> being typed. Neither is an extension point PKP supports, so 1.0.1.x does the same work through
> core hooks: the terms are split when the publication is **saved** (or imported), and the form
> shows them separated as soon as it is saved. A command-line native XML import is now split too,
> which the earlier versions missed.

## The problem

Authors select the keyword line in their manuscript, copy it, and paste the whole thing into
the keyword field:

```
Palatal Expansion Technique. Clinical Protocol. Orthopedic appliance.
```

That becomes **one** term in `controlled_vocab_entries`. The reader sees a sentence where a tag
should be, the keyword cloud shows the whole phrase, and `citation_keywords` goes out as a single
meta tag — which is exactly what Google Scholar and the indexers read.

It is not rare. In one journal we found it in **every published article, in all three
languages**: 24 records that should have been 107 terms.

## What it does

- Splits the vocabularies of a publication whenever it is **saved** — metadata form, submission
  wizard and REST API — or **created**.
- Splits what the **native XML import** stores, from the web or from the command line.
- Applies the per-journal settings: which vocabularies and which separators.
- Ships a command-line tool that repairs an **existing archive** in one pass.

### The splitting rules

Only **one** separator is used per entry: the first of the ones below that occurs in it.
A list written with semicolons and commas is therefore cut on the semicolons alone, which
keeps an inverted subject heading such as `Hypertension, Pregnancy-Induced` in one piece.

| Order | Separator | Example |
|-------|-----------|---------|
| 1 | Semicolon | `Ozone therapy; Oxidative stress; Regulation` |
| 2 | Comma followed by a space | `Ozone therapy, Oxidative stress, Regulation` |
| 3 | Period followed by a space | `Ozone therapy. Oxidative stress. Regulation` |

What is **never** cut:

- A period with no space after it, so legal references such as `Lei 13.964/2019` and
  `Decreto 12.456/2025` stay whole — cutting one destroys the citation.
- A period that closes a single letter, because that is an initial: `S. aureus`, `E. coli`
  and `C. albicans` stay whole.
- A comma with no space after it, so `1,5 mm` stays whole.

Terms are also cleaned: exotic spaces (no-break, en, thin, ideographic) and zero-width
characters become plain spaces, Unicode is normalised to NFC, and punctuation left dangling
at the edges is dropped. Duplicates within the same language are removed, case-insensitively.

**The comma is the risky one.** An inverted subject heading is a single term and would be cut
in two. Journals that index with MeSH or DeCS should turn the comma off.

## Installation

1. Download the package from the link at the top of this README.
2. In OJS, go to **Settings → Website → Plugins → Upload a new plugin** and upload the
   `.tar.gz`, or unpack it into `plugins/generic/`.
3. Enable **Controlled Vocabulary Splitter** in the plugin list.

## Configuration

**Settings → Website → Plugins → Controlled Vocabulary Splitter → Settings.**

- **Vocabularies** — which of the four are split. Everything is split by default; anything
  unticked is stored exactly as it was typed.
- **Separators** — which of the three are honoured. All are on by default.

Settings are per journal; there is nothing to configure at site level.

### Repairing an existing archive

The plugin acts when something is saved or imported, so an archive built before it was enabled
keeps its concatenated terms. The bundled command-line tool repairs it, applying each journal's
settings, and only prints what would change unless you ask it to write:

```bash
php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php
php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php --write
```

`--journal=path` limits it to one journal and `--submission=id` to one submission. Run it as the
account that owns the files, never as root. Journals where the plugin is off are skipped.

## How it works (technical)

Only core hooks are used, and every write goes through the public `Repo::controlledVocab()` API:

| Hook | What the plugin does |
|------|----------------------|
| `Publication::edit` | Splits the vocabularies sent in the edit before the publication is saved. |
| `Publication::add` | Splits what a new publication was created with (the hook runs after it is stored). |
| `nativexmlpublicationfilter::execute` | Splits what the native XML import has just stored. |

The hooks are registered unconditionally and each one checks whether the plugin is enabled in
the journal of the publication, as PKP asks of plugins that must also act in command-line runs
and jobs ([pkp/pkp-lib#11793](https://github.com/pkp/pkp-lib/issues/11793)). The settings form is
the standard plugin settings modal, with POST and CSRF validation. Nothing is added to the
reader-facing site or to the backend pages.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the plugin classes against the
  installed PKP, the splitting rules, the edit hook, the site level without a journal, the
  templates and the 38 translations. From the OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/controlledVocabSplitter/tests"
  ```

- **Cypress** (`cypress/tests/functional/ControlledVocabSplitter.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): enables the
  plugin, turns a separator off and puts it back, and saves a keyword line entered as one term to
  see it come back as separate terms (it fails with the plugin off).
- **Regression suites** for test installations (`tests/regression.php`, 359 cases, and
  `tests/regression_http.php`, 29 cases with a real login): the rules and real writes through the
  form, the REST API, the import hook and the settings screen. See [`tests/CASES.md`](tests/CASES.md).
- Verified on OJS 3.5.0.3, including a command-line native XML import.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you
are working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que separa **palavras-chave, assuntos,
áreas do conhecimento e agências de fomento coladas em uma linha só** nos termos que o autor
quis dizer, sempre que uma publicação é salva ou importada — só com hooks do núcleo, **sem
alterar nem substituir nada do OJS**.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.1 |

> **Atualização a partir da 1.0.0.x.** As versões anteriores substituíam uma classe do núcleo (o
> repositório de vocabulário controlado) e alteravam o campo compilado para separar a lista
> enquanto era digitada. Nenhum dos dois é ponto de extensão aceito pela PKP, então a 1.0.1.x faz
> o mesmo trabalho com hooks do núcleo: os termos são separados quando a publicação é **salva**
> (ou importada), e o formulário já os mostra separados logo depois de salvar. A importação XML
> nativa pela linha de comando agora também é separada, o que as versões anteriores deixavam
> passar.

### O problema

O autor seleciona a linha de palavras-chave do manuscrito, copia e cola tudo de uma vez no
campo:

```
Técnica de Expansão Palatina. Protocolo Clínico. Aparelho Ortopédico.
```

Isso vira **um** registro em `controlled_vocab_entries`. O leitor vê uma frase onde deveria haver
etiquetas, a nuvem de palavras-chave mostra a frase inteira e o `citation_keywords` sai como uma
meta tag só — justamente o que o Google Scholar e os indexadores leem.

Não é raro. Em uma revista o problema estava em **todos os artigos publicados, nos três
idiomas**: 24 registros que deveriam ser 107 termos.

### O que faz

- Separa os vocabulários da publicação sempre que ela é **salva** — formulário de metadados,
  assistente de submissão e API REST — ou **criada**.
- Separa o que a **importação XML nativa** grava, pela web ou pela linha de comando.
- Respeita a configuração de cada revista: quais vocabulários e quais separadores.
- Traz uma ferramenta de linha de comando que corrige um **acervo já gravado** de uma vez.

#### As regras de separação

Só **um** separador é usado por registro: o primeiro da lista abaixo que aparecer nele. Uma
lista escrita com ponto e vírgula e vírgulas é cortada só nos pontos e vírgulas, o que mantém
inteiro um descritor invertido como `Hipertensão Induzida pela Gravidez, Síndrome`.

| Ordem | Separador | Exemplo |
|-------|-----------|---------|
| 1 | Ponto e vírgula | `Ozonioterapia; Estresse oxidativo; Regulamentação` |
| 2 | Vírgula seguida de espaço | `Ozonioterapia, Estresse oxidativo, Regulamentação` |
| 3 | Ponto seguido de espaço | `Ozonioterapia. Estresse oxidativo. Regulamentação` |

O que **nunca** é cortado:

- Ponto sem espaço depois, o que mantém inteiras referências legais como `Lei 13.964/2019` e
  `Decreto 12.456/2025` — quebrar uma delas destrói a citação.
- Ponto que fecha uma letra sozinha, porque ali é inicial: `S. aureus`, `E. coli` e
  `C. albicans` ficam inteiros.
- Vírgula sem espaço depois, o que mantém inteiro `1,5 mm`.

Os termos também são limpos: espaços exóticos (NBSP, EN SPACE, THIN SPACE, ideográfico) e
caracteres de largura zero viram espaço comum, o Unicode é normalizado para NFC e a pontuação
solta nas bordas cai. Duplicatas no mesmo idioma são removidas, sem diferenciar maiúsculas.

**A vírgula é a arriscada.** Um descritor invertido é um termo só e seria cortado em dois.
Revista que indexa com MeSH ou DeCS deve desligar a vírgula.

### Instalação

1. Baixe o pacote pelo link no topo deste README.
2. No OJS, vá em **Configurações → Website → Plugins → Enviar um novo plugin** e envie o
   `.tar.gz`, ou descompacte em `plugins/generic/`.
3. Habilite o **Separador de Vocabulário Controlado** na lista de plugins.

### Configuração

**Configurações → Website → Plugins → Separador de Vocabulário Controlado → Configurações.**

- **Vocabulários** — quais dos quatro são separados. Por padrão, todos; o que for desmarcado
  é gravado exatamente como foi digitado.
- **Separadores** — quais dos três valem. Por padrão, todos.

A configuração é por revista; não há nada a configurar no nível do site.

#### Corrigir um acervo já gravado

O plugin age quando algo é salvo ou importado, então um acervo montado antes de ele ser ligado
continua com os termos colados. A ferramenta de linha de comando que acompanha o plugin corrige
tudo, com a configuração de cada revista, e só mostra o que mudaria até você mandar gravar:

```bash
php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php
php plugins/generic/controlledVocabSplitter/tools/fixExistingVocabs.php --write
```

`--journal=caminho` limita a uma revista e `--submission=id` a uma submissão. Rode como o dono
dos arquivos, nunca como root. Revistas com o plugin desligado são puladas.

### Como funciona (técnico)

Só hooks do núcleo, e toda gravação passa pela API pública `Repo::controlledVocab()`:

| Hook | O que o plugin faz |
|------|--------------------|
| `Publication::edit` | Separa os vocabulários enviados na edição antes de a publicação ser salva. |
| `Publication::add` | Separa o que a publicação nova trouxe (o hook roda depois de ela ser gravada). |
| `nativexmlpublicationfilter::execute` | Separa o que a importação XML nativa acabou de gravar. |

Os hooks são registrados sempre e cada um confere se o plugin está ligado na revista da
publicação, como a PKP pede para plugins que também precisam agir em execuções pela linha de
comando e em jobs ([pkp/pkp-lib#11793](https://github.com/pkp/pkp-lib/issues/11793)). A
configuração usa o modal padrão de plugins, com validação de POST e CSRF. Nada é acrescentado ao
site público nem às páginas do painel.

### Testes

- **PHPUnit** (`tests/*Test.php`, sobre `PKP\tests\PKPTestCase`): classes do plugin contra o PKP
  instalado, regras de separação, hook de edição, nível do site sem revista, templates e as 38
  traduções.
- **Cypress** (`cypress/tests/functional/`, rodado pelo
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) a cada push): liga o plugin,
  desliga e religa um separador, e salva uma linha de palavras-chave digitada como um termo só
  para vê-la voltar em termos separados (reprova com o plugin desligado).
- **Baterias de regressão** para instalações de teste (`tests/regression.php`, 359 casos, e
  `tests/regression_http.php`, 29 casos com login real). Veja [`tests/CASES.md`](tests/CASES.md).
- Verificado no OJS 3.5.0.3, inclusive com importação XML nativa pela linha de comando.

Os testes ficam no repositório e não vão no pacote de release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
