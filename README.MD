# CPSPlug  
**Two-way bridge between WooCommerce and the CardsPrint (Elixir) REST API.**

## What it does
- Automatically pushes new / updated **users**, **products** and **orders** to your Elixir backend  
- Exposes endpoint `https://your-site.com/wp-json/elixir/v1/user` so Elixir can update WooCommerce data  
- Stores API credentials in one settings screen: **Settings → CPSPlug**

## Requirements
- WordPress 5.9+  
- PHP 7.4+  
- WooCommerce 6.0+

## Install (from zip)
1. Download the latest release (`cpsplug.zip`)  
2. WordPress → Plugins → Add New → Upload → Activate  
3. Enter API key, login, password and store code → Save  
4. Data starts syncing immediately

## Development
All logic lives in the single bootstrap file `cpsplug.php`.  
Filters you can use:  
- `cps_api_url` – override remote URL  
- `cps_timeout` – change remote request timeout (default 5 s)

## Licence
GPL-2.0-or-later