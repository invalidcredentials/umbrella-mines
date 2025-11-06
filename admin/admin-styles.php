<?php
/**
 * Shared Admin Styles for Umbrella Mines
 */
?>
<style>
    /* Matrix Theme - Dark & Sleek */

    /* Remove WordPress admin padding and extend background */
    #wpbody-content {
        padding-bottom: 0 !important;
    }

    .umbrella-mines-page {
        background: #0a0e27;
        margin: -20px -20px 0 -20px;
        padding: 30px;
        min-height: calc(100vh - 32px);
        color: #e0e0e0;
        font-family: 'Segoe UI', system-ui, sans-serif;
    }

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #00ff41;
    }

    .page-header h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 700;
        letter-spacing: 2px;
        color: #00ff41;
        text-shadow: 0 0 10px rgba(0, 255, 65, 0.5);
    }

    .page-actions {
        display: flex;
        gap: 10px;
    }

    /* Tabs/Filters */
    .umbrella-mines-page .subsubsub {
        margin: 0 0 20px 0;
        padding: 0;
        list-style: none;
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        padding: 15px 20px;
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .umbrella-mines-page .subsubsub li {
        margin: 0;
    }

    .umbrella-mines-page .subsubsub a {
        color: #999;
        text-decoration: none;
        padding: 6px 12px;
        border-radius: 4px;
        transition: all 0.3s ease;
        font-size: 13px;
        letter-spacing: 0.5px;
    }

    .umbrella-mines-page .subsubsub a:hover {
        color: #00ff41;
        background: rgba(0, 255, 65, 0.1);
    }

    .umbrella-mines-page .subsubsub a.current {
        color: #00ff41;
        background: rgba(0, 255, 65, 0.15);
        font-weight: 600;
    }

    .umbrella-mines-page .subsubsub .count {
        color: #666;
        font-weight: normal;
    }

    .umbrella-mines-page .subsubsub a.current .count {
        color: #00ff41;
    }

    /* Tables */
    .umbrella-mines-page .wp-list-table {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: none;
    }

    .umbrella-mines-page .wp-list-table thead th {
        background: rgba(0, 255, 65, 0.05);
        border-bottom: 1px solid #2a3f5f;
        color: #00ff41;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        padding: 15px 12px;
    }

    .umbrella-mines-page .wp-list-table tbody tr {
        background: transparent;
        border-bottom: 1px solid #2a3f5f;
        transition: all 0.3s ease;
    }

    .umbrella-mines-page .wp-list-table tbody tr:hover {
        background: rgba(0, 255, 65, 0.05);
    }

    .umbrella-mines-page .wp-list-table tbody tr:last-child {
        border-bottom: none;
    }

    .umbrella-mines-page .wp-list-table tbody td {
        padding: 15px 12px;
        color: #e0e0e0;
        border: none;
        font-size: 13px;
    }

    .umbrella-mines-page .wp-list-table code {
        background: rgba(0, 255, 65, 0.1);
        color: #00ff41;
        padding: 3px 6px;
        border-radius: 4px;
        font-family: 'Courier New', monospace;
        font-size: 12px;
    }

    .umbrella-mines-page .striped tbody tr:nth-child(odd) {
        background: rgba(255, 255, 255, 0.02);
    }

    /* Buttons */
    .umbrella-mines-page .button,
    .umbrella-mines-page .button-primary,
    .umbrella-mines-page .button-secondary {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        color: #00ff41;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        padding: 6px 12px;
        border-radius: 4px;
        transition: all 0.3s ease;
        text-shadow: none;
        box-shadow: none;
    }

    .umbrella-mines-page .button:hover,
    .umbrella-mines-page .button-secondary:hover {
        background: rgba(0, 255, 65, 0.1);
        border-color: #00ff41;
        color: #00ff41;
        transform: translateY(-1px);
    }

    .umbrella-mines-page .button-primary {
        background: linear-gradient(135deg, #00ff41 0%, #00cc33 100%);
        border-color: #00ff41;
        color: #0a0e27;
    }

    .umbrella-mines-page .button-primary:hover {
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.4);
        transform: translateY(-1px);
    }

    /* Notices */
    .umbrella-mines-page .notice {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border-left: 4px solid #2a3f5f;
        border-radius: 4px;
        padding: 15px 20px;
        margin: 20px 0;
    }

    .umbrella-mines-page .notice p {
        color: #e0e0e0;
        margin: 0;
    }

    .umbrella-mines-page .notice-info {
        border-left-color: #0073aa;
    }

    .umbrella-mines-page .notice-success {
        border-left-color: #00ff41;
    }

    .umbrella-mines-page .notice-warning {
        border-left-color: #f0b849;
    }

    .umbrella-mines-page .notice-error {
        border-left-color: #dc3232;
    }

    /* Pagination */
    .umbrella-mines-page .tablenav {
        background: transparent;
        padding: 15px 0 0 0;
    }

    .umbrella-mines-page .tablenav-pages {
        color: #e0e0e0;
    }

    .umbrella-mines-page .tablenav-pages a,
    .umbrella-mines-page .tablenav-pages .current {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        color: #00ff41;
        padding: 6px 12px;
        border-radius: 4px;
        transition: all 0.3s ease;
    }

    .umbrella-mines-page .tablenav-pages a:hover {
        background: rgba(0, 255, 65, 0.1);
        border-color: #00ff41;
    }

    .umbrella-mines-page .tablenav-pages .current {
        background: rgba(0, 255, 65, 0.15);
        font-weight: 600;
    }

    /* Modal */
    .umbrella-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(10, 14, 39, 0.95);
        z-index: 100000;
        backdrop-filter: blur(5px);
    }

    .umbrella-modal {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 2px solid #00ff41;
        padding: 30px;
        max-width: 900px;
        max-height: 85vh;
        overflow-y: auto;
        border-radius: 12px;
        box-shadow: 0 0 50px rgba(0, 255, 65, 0.3);
    }

    .umbrella-modal h2 {
        color: #00ff41;
        margin-top: 0;
        letter-spacing: 2px;
        text-transform: uppercase;
    }

    .umbrella-modal::-webkit-scrollbar {
        width: 12px;
    }

    .umbrella-modal::-webkit-scrollbar-track {
        background: #0a0e27;
    }

    .umbrella-modal::-webkit-scrollbar-thumb {
        background: #00ff41;
        border-radius: 6px;
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
        border: 1px solid #2a3f5f;
        border-radius: 8px;
        padding: 20px;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        border-color: #00ff41;
        box-shadow: 0 0 20px rgba(0, 255, 65, 0.1);
        transform: translateY(-2px);
    }

    .stat-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 2px;
        color: #00ff41;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: #fff;
        margin-bottom: 5px;
        line-height: 1;
    }

    .stat-meta {
        font-size: 12px;
        color: #666;
        letter-spacing: 0.5px;
    }
</style>
