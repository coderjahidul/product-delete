# Product Delete

Product Delete is a powerful WooCommerce utility plugin designed to help store owners efficiently clean up their product catalog. Unlike standard deletions, this plugin ensures that associated media assets (thumbnails and gallery images) are also permanently removed from the server, preventing "orphaned" images from cluttering your media library.

## Key Features

- **Media Cleanup**: Automatically deletes product thumbnails and gallery images when a product is removed.
- **Bulk Deletion**: Process multiple products at once with configurable limits.
- **Category Filtering**: Limit deletions to specific product categories.
- **REST API Support**: Fully automate your cleanup workflow via a dedicated REST API endpoint.
- **Premium Interface**: Modern, user-friendly settings page built for the WordPress admin.

## Installation

1. Upload the `product-delete` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your preferences under **Settings > Product Delete**.

## Configuration

Navigate to **Settings > Product Delete** to manage:
- **Delete Limit**: Set the maximum number of products to delete per request.
- **Product Categories**: Select specific categories to target for deletion.

## REST API Integration

Automate your cleanup by sending a `POST` request to the following endpoint:

`POST /wp-json/product-delete/v1/delete-products`

### Parameters

| Parameter | Type | Description |
| :--- | :--- | :--- |
| `limit` | integer | (Optional) Number of products to delete. Overrides admin settings. |
| `category_ids` | array | (Optional) Array of category IDs to filter products. Overrides admin settings. |

### Example Request (cURL)

```bash
curl -X POST https://your-site.com/wp-json/product-delete/v1/delete-products \
     -H "Content-Type: application/json" \
     -d '{"limit": 20, "category_ids": [15, 22]}'
```

### Security Note

> [!IMPORTANT]
> The REST API endpoint currently does not require authentication. Ensure you restrict access to this endpoint if your site is public and you are not using standard WordPress REST API authentication methods.

## License

GPLv2 or later.
