<?php
/**
 * Campaigns Management Page
 * 
 * @package Bema_CRM
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">CRM Campaigns</h1>
    <hr class="wp-header-end">

    <!-- Search -->
    <form method="get">
        <input type="hidden" name="page" value="bema-crm-campaigns">
        <p class="search-box">
            <label class="screen-reader-text" for="campaign-search-input">Search Campaigns:</label>
            <input type="search" id="campaign-search-input" name="s" value="">
            <input type="submit" id="search-submit" class="button" value="Search Campaigns">
        </p>
    </form>

    <!-- Campaigns Table -->
    <form method="post">
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column">
                        <input type="checkbox">
                    </td>
                    <th scope="col" class="manage-column">Campaign</th>
                    <th scope="col" class="manage-column">Product</th>
                    <th scope="col" class="manage-column">Subscribers</th>
                    <th scope="col" class="manage-column">Revenue</th>
                    <th scope="col" class="manage-column">Status</th>
                    <th scope="col" class="manage-column">View Subscribers</th>
                    <th scope="col" class="manage-column">Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Example Row -->
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" name="campaign[]" value="163667485400237380">
                    </th>
                    <td>2024_WURRDIE_WT<br><small>ID: 163667485400237380</small></td>
                    <td>Product #1679</td>
                    <td>350</td>
                    <td>$1,200</td>
                    <td><span class="status active">Active</span></td>
                    <td><a href="#">View Subscribers</a></td>
                    <td>
                        <a href="#" class="button">Resync</a>
                        <a href="#" class="delete-campaign" style="color:red;">Delete</a>
                    </td>
                </tr>
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" name="campaign[]" value="165023190687418052">
                    </th>
                    <td>2024_ETB_GIG<br><small>ID: 165023190687418052</small></td>
                    <td>Product #1732</td>
                    <td>210</td>
                    <td>$890</td>
                    <td><span class="status completed">Completed</span></td>
                    <td><a href="#">View Subscribers</a></td>
                    <td>
                        <a href="#" class="button">Resync</a>
                        <a href="#" class="delete-campaign" style="color:red;">Delete</a>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Bulk Action & Pagination -->
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action">
                    <option value="-1">Bulk actions</option>
                    <option value="delete">Delete</option>
                </select>
                <input type="submit" class="button action" value="Apply">
            </div>
            <div class="tablenav-pages">
                <span class="displaying-num">2 items</span>
                <span class="pagination-links">
                    <a class="tablenav-pages-navspan button disabled">«</a>
                    <a class="tablenav-pages-navspan button disabled">‹</a>
                    <span class="paging-input">1 of <span class="total-pages">1</span></span>
                    <a class="tablenav-pages-navspan button disabled">›</a>
                    <a class="tablenav-pages-navspan button disabled">»</a>
                </span>
            </div>
        </div>
    </form>
</div>

<style>
    .status.active { color: green; font-weight: bold; }
    .status.completed { color: #555; font-weight: bold; }
    .status.draft { color: #999; font-style: italic; }
</style>
