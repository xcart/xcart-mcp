# API Reference — X-Cart MCP Server

Generated from live MCP introspection (`tools/list`, `resources/list`, `resources/templates/list`, `prompts/list`), plus the gated destructive tools documented separately below.

**51 tools** (48 exposed by default + 3 gated), **29 resources** (24 fixed + 5 templated), **13 prompts**.

---

## Tools

### `asap_auto_map`

**Auto-Map ASAP Categories**


Automatically map ASAP Network categories to X-Cart categories by matching names. Handles ASAP duplication: when the same subcategory appears under multiple ASAP root categories, all duplicates are mapped to the same X-Cart category. Returns proposed mappings for review, or applies them if confirmed.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `apply` | boolean | no | `false` |
| `exactOnly` | boolean | no | `false` |

### `asap_bulk_map`

**Bulk Map ASAP Categories**


Map multiple ASAP Network categories at once. Accepts an array of {asap_id, xcart_category_id} pairs. Use this after reviewing auto_map proposals or for batch mapping from LLM-generated suggestions.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `mappings` | array | yes | — |

### `asap_categories_list`

**List ASAP Categories**


List ASAP Network imported categories with mapping status. Filter by mapped/unmapped, parent, or search by name. ASAP has ~5000 categories with heavy duplication across root categories.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `filter` | string | no | — |
| `parentId` | integer | no | — |
| `search` | string | no | — |
| `limit` | integer | no | `50` |
| `offset` | integer | no | `0` |

### `asap_category_map`

**Map ASAP Category**


Map an ASAP Network category to an existing X-Cart category. If xcart_category_id is omitted, creates a new X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `asapCategoryId` | integer | yes | — |
| `xcartCategoryId` | integer | no | — |
| `createUnderParentId` | integer | no | — |

### `asap_category_unmap`

**Unmap ASAP Category**


Remove mapping between an ASAP Network category and X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `asapCategoryId` | integer | yes | — |

### `asap_deduplicate_report`

**ASAP Deduplication Report**


Analyze ASAP Network category duplication. Returns categories that appear under multiple root categories with their full paths. Essential for understanding the ASAP category mess before mapping.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `limit` | integer | no | `50` |

### `brand_get`

**Get Brand**


Get detailed brand info: name, description, meta, logo, product count, AAIA ID.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `brandId` | integer | yes | — |

### `brand_list`

**List Brands**


List all brands with product counts, logo status, and enabled state. Search by name.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `search` | string | no | — |
| `enabled` | boolean | no | — |
| `sortBy` | string | no | `"name"` |
| `limit` | integer | no | `100` |
| `offset` | integer | no | `0` |

### `brand_products`

**List Brand Products**


List products for a given brand with basic product info.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `brandId` | integer | yes | — |
| `limit` | integer | no | `50` |
| `offset` | integer | no | `0` |

### `brand_toggle`

**Toggle Brand**


Enable or disable a brand.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `brandId` | integer | yes | — |
| `enabled` | boolean | yes | — |

### `brand_update`

**Update Brand**


Update brand description, meta title, meta description, meta keywords, or position.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `brandId` | integer | yes | — |
| `description` | string | no | — |
| `metaTitle` | string | no | — |
| `metaDescription` | string | no | — |
| `metaKeywords` | string | no | — |
| `position` | integer | no | — |

### `category_assign_product`

**Assign Product to Category**


Assign a product to a category


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productId` | integer | yes | — |
| `categoryId` | integer | yes | — |

### `category_create`

**Create Category**


Create a new category. Optionally specify parent for subcategory.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `name` | string | yes | — |
| `parentId` | integer | no | — |
| `description` | string | no | — |
| `enabled` | boolean | no | `true` |

### `category_remove_product`

**Remove Product from Category**


Remove a product from a category


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productId` | integer | yes | — |
| `categoryId` | integer | yes | — |

### `category_update`

**Update Category**


Update an existing category (name, description, enabled status, parent).


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `categoryId` | integer | yes | — |
| `name` | string | no | — |
| `description` | string | no | — |
| `enabled` | boolean | no | — |
| `parentId` | integer | no | — |

### `global_search`

**Global Search**


Search across products, orders, categories, and customers. Returns grouped results.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `query` | string | yes | — |
| `entityType` | string | no | — |
| `limit` | integer | no | `10` |

### `order_add_note`

**Add Order Note**


Add an admin note to an order


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `orderId` | integer | yes | — |
| `note` | string | yes | — |

### `order_get_items`

**Get Order Items**


Get detailed list of items in an order


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `orderId` | integer | yes | — |

### `order_search`

**Search Orders**


Search orders by date range, status, amount, customer email


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `dateFrom` | string | no | — |
| `dateTo` | string | no | — |
| `paymentStatus` | string | no | — |
| `shippingStatus` | string | no | — |
| `customerEmail` | string | no | — |
| `totalMin` | number | no | — |
| `totalMax` | number | no | — |
| `sortBy` | string | no | `"date"` |
| `sortOrder` | string | no | `"desc"` |
| `limit` | integer | no | `20` |
| `offset` | integer | no | `0` |

### `order_update_status`

**Update Order Status**


Update order payment and/or shipping status


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `orderId` | integer | yes | — |
| `paymentStatus` | string | no | — |
| `shippingStatus` | string | no | — |

### `product_create`

**Create Product**


Create a new product in the catalog


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `name` | string | yes | — |
| `sku` | string | yes | — |
| `price` | number | yes | — |
| `description` | string | no | — |
| `briefDescription` | string | no | — |
| `categoryId` | integer | no | — |
| `quantity` | integer | no | — |
| `weight` | number | no | — |
| `enabled` | boolean | no | `true` |

### `product_search`

**Search Products**


Search products by name, SKU, price range, category, stock status. Returns up to {limit} results.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `query` | string | no | — |
| `sku` | string | no | — |
| `priceMin` | number | no | — |
| `priceMax` | number | no | — |
| `categoryId` | integer | no | — |
| `inStock` | boolean | no | — |
| `enabled` | boolean | no | — |
| `sortBy` | string | no | `"name"` |
| `sortOrder` | string | no | `"asc"` |
| `limit` | integer | no | `20` |
| `offset` | integer | no | `0` |

### `product_update`

**Update Product**


Update an existing product. Only provided fields will be changed.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productId` | integer | yes | — |
| `name` | string | no | — |
| `sku` | string | no | — |
| `price` | number | no | — |
| `description` | string | no | — |
| `briefDescription` | string | no | — |
| `quantity` | integer | no | — |
| `weight` | number | no | — |
| `enabled` | boolean | no | — |

### `product_update_stock`

**Update Product Stock**


Update product stock quantity. Use relative=false for absolute value, relative=true for +/- adjustment.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productId` | integer | yes | — |
| `quantity` | integer | yes | — |
| `relative` | boolean | no | `false` |

### `report_inventory`

**Inventory Report**


Inventory report: stock value, out of stock items, low stock items, overstocked items


### `report_sales`

**Sales Report**


Sales report for a period: revenue, orders count, average order value, comparison with previous period


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `period` | string | no | `"month"` |
| `dateFrom` | string | no | — |
| `dateTo` | string | no | — |

### `report_top_products`

**Top Products Report**


Top selling products by revenue or quantity for a given period


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `limit` | integer | no | `10` |
| `period` | string | no | `"month"` |
| `sortBy` | string | no | `"revenue"` |

### `sema_auto_map`

**Auto-Map SEMA Data Categories**


Automatically map SEMA Data categories to X-Cart categories by matching names. When the same subcategory appears under multiple SEMA Data root categories, all duplicates are mapped to the same X-Cart category. Returns proposed mappings for review, or applies them if confirmed.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `apply` | boolean | no | `false` |
| `exactOnly` | boolean | no | `false` |

### `sema_bulk_map`

**Bulk Map SEMA Data Categories**


Map multiple SEMA Data categories at once. Accepts an array of {sema_id, xcart_category_id} pairs. Use this after reviewing auto_map proposals or for batch mapping from LLM-generated suggestions.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `mappings` | array | yes | — |

### `sema_categories_list`

**List SEMA Data Categories**


List SEMA Data imported categories with mapping status. Filter by mapped/unmapped, parent, or search by name. SEMA Data has ~5800 categories that may repeat across root categories.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `filter` | string | no | — |
| `parentId` | integer | no | — |
| `search` | string | no | — |
| `limit` | integer | no | `50` |
| `offset` | integer | no | `0` |

### `sema_category_map`

**Map SEMA Data Category**


Map a SEMA Data category to an existing X-Cart category. If xcart_category_id is omitted, creates a new X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `semaCategoryId` | integer | yes | — |
| `xcartCategoryId` | integer | no | — |
| `createUnderParentId` | integer | no | — |

### `sema_category_unmap`

**Unmap SEMA Data Category**


Remove mapping between a SEMA Data category and X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `semaCategoryId` | integer | yes | — |

### `sema_deduplicate_report`

**SEMA Data Deduplication Report**


Analyze SEMA Data category duplication. Returns categories that appear under multiple root categories with their full paths. Useful for understanding the SEMA Data category structure before mapping.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `limit` | integer | no | `50` |

### `turn14_auto_map`

**Auto-Map Turn14 Categories**


Automatically map Turn14 categories to X-Cart categories by matching names. Returns proposed mappings for review, or applies them if confirmed.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `apply` | boolean | no | `false` |
| `exactOnly` | boolean | no | `false` |

### `turn14_bulk_map`

**Bulk Map Turn14 Categories**


Map multiple Turn14 categories at once. Accepts an array of {turn14_id, xcart_category_id} pairs.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `mappings` | array | yes | — |

### `turn14_categories_list`

**List Turn14 Categories**


List Turn14 imported categories with mapping status. Filter by mapped/unmapped, parent, or search by name.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `filter` | string | no | — |
| `parentId` | integer | no | — |
| `search` | string | no | — |
| `limit` | integer | no | `50` |
| `offset` | integer | no | `0` |

### `turn14_category_map`

**Map Turn14 Category**


Map a Turn14 category to an existing X-Cart category. If xcart_category_id is omitted, creates a new X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `turn14CategoryId` | integer | yes | — |
| `xcartCategoryId` | integer | no | — |
| `createUnderParentId` | integer | no | — |

### `turn14_category_unmap`

**Unmap Turn14 Category**


Remove mapping between a Turn14 category and X-Cart category.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `turn14CategoryId` | integer | yes | — |

### `vehicle_bulk_toggle_makes`

**Bulk Toggle Vehicle Makes**


Enable or disable multiple Makes at once by name. Supports wildcards. Example: names=["RAM","Jeep","Ford"]


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `names` | array | yes | — |
| `enabled` | boolean | yes | — |
| `cascade` | boolean | no | `true` |

### `vehicle_bulk_toggle_models`

**Bulk Toggle Vehicle Models**


Enable or disable multiple Models at once for a given Make. Match by exact names or wildcard patterns. Example: makeId=5, names=["F-150","Ranger","Explorer*"], enabled=true


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `makeId` | integer | yes | — |
| `names` | array | yes | — |
| `enabled` | boolean | yes | — |
| `cascade` | boolean | no | `true` |

### `vehicle_make_toggle`

**Toggle Vehicle Make**


Enable or disable a Make and optionally all its children (Models, Years, Submodels). Use cascade=true to propagate.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `makeId` | integer | yes | — |
| `enabled` | boolean | yes | — |
| `cascade` | boolean | no | `true` |

### `vehicle_makes_list`

**List Vehicle Makes**


List all vehicle Makes with enabled/disabled status and model counts. Filter by enabled status or search by name.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `enabled` | boolean | no | — |
| `search` | string | no | — |
| `limit` | integer | no | `100` |
| `offset` | integer | no | `0` |

### `vehicle_model_toggle`

**Toggle Vehicle Model**


Enable or disable a specific Model (Level2) with cascade to its Years and Submodels.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `modelId` | integer | yes | — |
| `enabled` | boolean | yes | — |

### `vehicle_models_keep_only`

**Keep Only Vehicle Models**


Disable ALL Models for a Make, then enable only the specified ones (with cascade to Years/Submodels). Perfect for filtering: "For Ford, keep only F-150, Ranger, Bronco". Supports wildcards.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `makeId` | integer | yes | — |
| `keepNames` | array | yes | — |
| `yearFrom` | integer | no | — |
| `yearTo` | integer | no | — |

### `vehicle_models_list`

**List Vehicle Models**


List vehicle Models for a given Make. Filter by enabled status or search by name.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `makeId` | integer | yes | — |
| `enabled` | boolean | no | — |
| `search` | string | no | — |
| `limit` | integer | no | `100` |
| `offset` | integer | no | `0` |

### `vehicle_set_year_range`

**Set Vehicle Year Range**


Enable only Years within a range for a given Make (or all Makes). Years outside the range are disabled.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `yearFrom` | integer | yes | — |
| `yearTo` | integer | yes | — |
| `makeId` | integer | no | — |
| `makeName` | string | no | — |

### `vehicle_stats`

**Vehicle Statistics**


Get vehicle database statistics: counts per level, enabled/disabled breakdown, fitment counts.


### `vehicle_years_list`

**List Vehicle Years**


List available Years for a given Model, with enabled status.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `modelId` | integer | yes | — |
| `enabled` | boolean | no | — |

---

## Gated destructive tools

> Hidden from `tools/list` and rejected at call time unless `dangerous_tools_enabled` is set in **Admin → Settings → MCP AI Integration**. All carry `destructiveHint: true`.

### `product_delete`

**Delete Product**


Delete a product by ID. This action is irreversible.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productId` | integer | yes | — |

### `product_bulk_update_prices`

**Bulk Update Product Prices**


Update prices for multiple products by percentage. Positive = increase, negative = decrease.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `productIds` | integer[] | yes | — |
| `percentChange` | number | yes | — |

### `vehicle_disable_all_then_enable`

**Disable All Then Enable Vehicle Makes**


Disable ALL Makes, then enable only the specified ones. Perfect for onboarding: "enable only RAM, Jeep, and Ford from 1999-2026". Requires the QSL\Make module.


| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `makeNames` | string[] | yes | — |
| `yearFrom` | integer | no | — |
| `yearTo` | integer | no | — |

---

## Resources (fixed)

### `ui://sales-dashboard`

**Sales Dashboard (App)** — `sales_dashboard_ui`


Interactive sales dashboard rendered in supporting MCP clients. Pulls figures from the report_sales tool.

_MIME:_ `text/html;profile=mcp-app`

### `xcart://asap/categories`

**ASAP Category Tree** — `asap_category_tree`


ASAP Network imported category tree with mapping status to X-Cart categories. ASAP categories have heavy duplication — same subcategories appear under different root categories.

_MIME:_ `application/json`

### `xcart://asap/categories/unmapped`

**ASAP Unmapped Categories** — `asap_unmapped_categories`


ASAP Network categories not yet mapped to X-Cart categories

_MIME:_ `application/json`

### `xcart://asap/mapping-summary`

**ASAP Mapping Summary** — `asap_mapping_summary`


Summary of ASAP Network to X-Cart category mapping progress with exact name match suggestions. Includes duplicate detection — ASAP has many categories that appear under multiple root categories.

_MIME:_ `application/json`

### `xcart://brands/list`

**Brands List** — `brands_list`


All brands with product counts and logo status.

_MIME:_ `application/json`

### `xcart://categories/tree`

**Category Tree** — `category_tree`


Full category hierarchy as a tree with product counts

_MIME:_ `application/json`

### `xcart://orders/pending`

**Pending Orders** — `pending_orders`


Orders requiring attention: unpaid, unshipped, or with issues

_MIME:_ `application/json`

### `xcart://orders/recent`

**Recent Orders** — `recent_orders`


Last 50 orders: id, date, total, payment status, shipping status, customer email

_MIME:_ `application/json`

### `xcart://orders/stats`

**Order Stats** — `order_stats`


Order statistics: total count, revenue, average order value, status breakdown, today/week/month totals

_MIME:_ `application/json`

### `xcart://products/list`

**Product List** — `product_list`


List of enabled products: id, name, sku, price, stock quantity. Limited to 50 items. Use product_search tool for filtering.

_MIME:_ `application/json`

### `xcart://products/low-stock`

**Low Stock Products** — `low_stock_products`


Products with quantity <= 5 (low stock threshold). Sorted by quantity ascending.

_MIME:_ `application/json`

### `xcart://products/stats`

**Product Stats** — `product_stats`


Product catalog statistics: total count, enabled/disabled, in stock/out of stock, price range, categories count

_MIME:_ `application/json`

### `xcart://profiles/stats`

**Profile Stats** — `profile_stats`


Customer statistics: total, new this month, top spenders

_MIME:_ `application/json`

### `xcart://sema/categories`

**SEMA Data Category Tree** — `sema_category_tree`


SEMA Data imported category tree with mapping status to X-Cart categories.

_MIME:_ `application/json`

### `xcart://sema/categories/unmapped`

**SEMA Data Unmapped Categories** — `sema_unmapped_categories`


SEMA Data categories not yet mapped to X-Cart categories

_MIME:_ `application/json`

### `xcart://sema/mapping-summary`

**SEMA Data Mapping Summary** — `sema_mapping_summary`


Summary of SEMA Data to X-Cart category mapping progress with exact name match suggestions and duplicate detection.

_MIME:_ `application/json`

### `xcart://store/config`

**Store Config** — `store_config`


Store configuration: name, URL, currency, language, timezone. No secrets or credentials.

_MIME:_ `application/json`

### `xcart://store/dashboard`

**Store Dashboard** — `store_dashboard`


Store dashboard: today sales, orders, recent activity, low stock and pending order counts

_MIME:_ `application/json`

### `xcart://store/modules`

**Active Modules** — `active_modules`


List of active X-Cart modules with versions

_MIME:_ `application/json`

### `xcart://turn14/categories`

**Turn14 Category Tree** — `turn14_category_tree`


Turn14 imported category tree with mapping status to X-Cart categories

_MIME:_ `application/json`

### `xcart://turn14/categories/unmapped`

**Turn14 Unmapped Categories** — `turn14_unmapped_categories`


Turn14 categories not yet mapped to X-Cart categories

_MIME:_ `application/json`

### `xcart://turn14/mapping-summary`

**Turn14 Mapping Summary** — `turn14_mapping_summary`


Summary of Turn14 to X-Cart category mapping progress with suggestions

_MIME:_ `application/json`

### `xcart://vehicles/makes`

**Vehicle Makes** — `vehicle_makes`


List of all vehicle makes with enabled status and model counts.

_MIME:_ `application/json`

### `xcart://vehicles/stats`

**Vehicle Stats** — `vehicle_stats`


Vehicle database stats: total/enabled/disabled per level (makes, models, years, submodels), fitment count.

_MIME:_ `application/json`


---

## Resources (templated)

### `xcart://categories/{categoryId}`

**Category Detail** — `category_detail`


Category details with subcategories and product count

_MIME:_ `application/json`

### `xcart://categories/{categoryId}/products`

**Category Products** — `category_products`


Products in a specific category (id, name, sku, price, stock)

_MIME:_ `application/json`

### `xcart://orders/{orderId}`

**Order Detail** — `order_detail`


Full order details: items, totals, shipping address, payment info, notes, history

_MIME:_ `application/json`

### `xcart://products/{productId}`

**Product Detail** — `product_detail`


Full product details: name, description, price, stock, images, categories, attributes

_MIME:_ `application/json`

### `xcart://profiles/{profileId}`

**Profile Detail** — `profile_detail`


Customer profile: name, email, orders count, total spent, addresses. No passwords or payment data.

_MIME:_ `application/json`


---

## Prompts

### `analyze_inventory`

**Analyze Inventory**


Inventory analysis: low stock, out of stock, overstock, reorder recommendations


### `analyze_store`

**Analyze Store**


Comprehensive store health analysis: sales trends, inventory issues, pending orders


### `brand_audit`

**Brand Audit**


Audit brands in the store. Find brands with missing logos, zero products, and recommend cleanup actions.


### `category_audit`

**Category Audit**


Audit category structure: empty categories, unbalanced tree, orphan products


### `category_audit_suppliers`

**Audit Supplier Categories**


Audit category mapping across all suppliers (Turn14, ASAP, SEMA). Find inconsistencies, unmapped categories, and suggest unified taxonomy.


### `daily_orders_review`

**Daily Orders Review**


Daily orders summary: new, processed, shipped, issues


### `find_problem_orders`

**Find Problem Orders**


Find orders with potential problems: stuck, unpaid, returns


### `map_asap_categories`

**Map ASAP Categories**


Guided workflow to map ASAP Network categories to X-Cart categories. Handles duplication, suggests mappings, and applies them in bulk.


### `map_sema_categories`

**Map SEMA Data Categories**


Guided workflow to map SEMA Data categories to X-Cart categories. Suggests name matches and applies them in bulk.


### `optimize_catalog`

**Optimize Catalog**


Find catalog issues: missing descriptions, images, pricing problems, empty categories


### `pricing_analysis`

**Pricing Analysis**


Analyze pricing: distribution, outliers, margin analysis


### `process_pending_orders`

**Process Pending Orders**


Review all pending orders and suggest actions for each


### `vehicle_onboarding`

**Vehicle Onboarding**


Guided workflow for setting up vehicles for a new client. Disables all vehicles, then enables only the specified makes and year range.


| Argument | Required | Description |
|----------|----------|-------------|
| `makes` | no |  |
| `yearFrom` | no |  |
| `yearTo` | no |  |
