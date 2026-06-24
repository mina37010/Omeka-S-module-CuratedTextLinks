# Curated Text Links

English | [日本語](#日本語)

Curated Text Links is an Omeka S module for manually curating links inside item text. Editors can select part of a literal value and connect it to an external URI, an authority record, or another Omeka resource. Approved links are rendered on public item pages.

## Features

- Inline link rendering for literal values.
- Site-side annotation UI for permitted users.
- Review workflow for candidate and approved annotations.
- Bulk application and rollback for repeated text links.
- Optional authority lookup for Wikidata and NDL.
- Site blocks for link networks and reading cards.
- Resource page block for item-level link networks.

## Requirements

- Omeka S 4.x.
- PHP extensions required by Omeka S, including `mbstring`.
- Database permissions to create and update the module tables during installation.

## Installation

1. Copy to `modules/CuratedTextLinks`.
2. In the Omeka S admin, open **Modules**.
3. Install **Curated Text Links**.
4. Configure permitted roles and authority lookup settings as needed.

## Configuration

The module settings include:

- **Target property term**: property used when writing linked metadata back to items. Default: `schema:about`.
- **Allowed roles**: roles allowed to use the annotation UI.
- **Authority search**: optional Wikidata and NDL lookup.
- **Authority cache TTL**: cache lifetime for authority search responses.

## Data

This module creates the following tables:

- `curated_text_link_annotation`
- `curated_text_link_batch`
- `curated_text_link_authority_cache`
- `curated_text_link_alias`

Uninstalling the module drops these tables. Export or back up annotation data before uninstalling from a production site.

## Public Blocks

Curated Text Links provides public site blocks for curated-link views.

- **Curated Text Links: network**
- **Curated Text Links: reading cards**

## Copyright

Copyright (c) 2026 Asaka Hinata.

## License

This module is released under the GPL-3.0-or-later license.

---

## 日本語

[English](#curated-text-links) | 日本語

Curated Text Links は、Omeka S のアイテム本文中にある文字列へ手動でリンクを付与するためのモジュールです。編集者はリテラル値の一部を選択し、外部 URI、典拠レコード、または別の Omeka リソースへ接続できます。承認済みのリンクは公開アイテムページで表示されます。

## 機能

- リテラル値内のインラインリンク表示。
- 権限を持つユーザー向けのサイト側アノテーション UI。
- 候補アノテーションと承認済みアノテーションのレビュー手順。
- 繰り返し出現する文字列への一括適用とロールバック。
- Wikidata と NDL の任意の典拠検索。
- リンクネットワークと読書カード用のサイトブロック。
- アイテム単位のリンクネットワーク用リソースページブロック。

## 要件

- Omeka S 4.x。
- Omeka S が必要とする PHP 拡張。`mbstring` を含みます。
- インストール時にモジュール用テーブルを作成・更新できるデータベース権限。

## インストール

1.  `modules/CuratedTextLinks` にコピーします。
2. Omeka S 管理画面で **Modules** を開きます。
3. **Curated Text Links** をインストールします。
4. 必要に応じて、許可するロールと典拠検索設定を構成します。

## 設定

モジュール設定には次の項目があります。

- **Target property term**: リンク済みメタデータをアイテムへ書き戻す際に使うプロパティ。初期値は `schema:about` です。
- **Allowed roles**: アノテーション UI を利用できるロール。
- **Authority search**: Wikidata と NDL の任意の典拠検索。
- **Authority cache TTL**: 典拠検索レスポンスのキャッシュ保持時間。

## データ

このモジュールは次のテーブルを作成します。

- `curated_text_link_annotation`
- `curated_text_link_batch`
- `curated_text_link_authority_cache`
- `curated_text_link_alias`

モジュールをアンインストールすると、これらのテーブルは削除されます。本番環境でアンインストールする前に、アノテーションデータをエクスポートまたはバックアップしてください。

## 公開ブロック

Curated Text Links は、キュレーション済みリンクを表示する公開サイトブロックを提供します。

- **Curated Text Links: network**
- **Curated Text Links: reading cards**

## Copyright

Copyright (c) 2026 浅香ひなた (Asaka Hinata).

## ライセンス

このモジュールは GPL-3.0-or-later ライセンスで公開します。
