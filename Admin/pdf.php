<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Preview - Inventory Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS remains unchanged */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .controls {
            display: flex;
            justify-content: space-between;
            padding: 20px;
            background: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .btn {
            padding: 12px 20px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #4e73df;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2e59d9;
        }
        
        .btn-success {
            background: #1cc88a;
            color: white;
        }
        
        .btn-success:hover {
            background: #17a673;
        }
        
        .btn-secondary {
            background: #858796;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #6b6d7d;
        }
        
        .pdf-preview {
            background: white;
            padding: 25px;
            position: relative;
        }
        
        .pdf-page {
            width: 100%;
            min-height: 1122px; /* A4 proportions */
            background: white;
            margin: 0 auto;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
            padding: 40px;
            position: relative;
        }
        
        .pdf-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 20px;
        }
        
        .pdf-title {
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .pdf-subtitle {
            font-size: 16px;
            color: #7b8a8b;
        }
        
        .pdf-info {
            margin-bottom: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #4e73df;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pdf-info p {
            margin: 0;
            flex: 1;
            min-width: 200px;
        }
        
        .pdf-table-container {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            -webkit-overflow-scrolling: touch;
        }
        
        .pdf-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .pdf-table th {
            background: #2c3e50;
            color: white;
            padding: 12px 8px;
            text-align: center;
            border: 1px solid #dee2e6;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pdf-table td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            vertical-align: middle;
            font-size: 13px;
            word-wrap: break-word;
        }
        
        .pdf-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .pdf-footer {
            text-align: right;
            font-size: 12px;
            color: #6c757d;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            position: absolute;
            bottom: 40px;
            right: 40px;
            left: 40px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 25px;
            gap: 10px;
        }
        
        .page-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #4e73df;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }
        
        .page-info {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        /* Responsive design */
        @media (max-width: 900px) {
            .pdf-page {
                padding: 30px;
            }
            
            .pdf-title {
                font-size: 20px;
            }
            
            .pdf-info {
                flex-direction: column;
                gap: 10px;
            }
            
            .pdf-info p {
                min-width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
            }
            
            .btn-group {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .btn {
                flex: 1;
                min-width: 120px;
                justify-content: center;
            }
            
            .pdf-page {
                padding: 20px;
                min-height: 900px;
            }
            
            .pdf-table th,
            .pdf-table td {
                padding: 8px 5px;
                font-size: 12px;
            }
            
            .pdf-footer {
                position: static;
                margin-top: 20px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .header {
                padding: 20px 15px;
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .pdf-page {
                padding: 15px;
            }
            
            .pdf-table th,
            .pdf-table td {
                padding: 6px 3px;
                font-size: 11px;
            }
            
            .btn {
                padding: 10px 15px;
            }
        }
        
        /* Print styles */
        @media print {
            body, .container {
                margin: 0;
                padding: 0;
                box-shadow: none;
                background: white;
            }
            
            .header, .controls, .pagination {
                display: none;
            }
            
            .pdf-preview {
                padding: 0;
            }
            
            .pdf-page {
                box-shadow: none;
                min-height: auto;
                page-break-after: always;
            }
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
            font-size: 18px;
            color: #6c757d;
        }

        .loading i {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PDF Report Preview</h1>
            <p>Portrait layout optimized for both desktop and mobile</p>
        </div>
        
        <div class="controls">
            <div class="btn-group">
                <button class="btn btn-primary" id="backButton">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
            
            <div class="btn-group">
                <button class="btn btn-secondary" id="printButton">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn btn-success" id="downloadButton">
                    <i class="fas fa-download"></i> Download
                </button>
            </div>
        </div>
        
        <div class="pdf-preview">
            <div class="pdf-page">
                <div class="pdf-header">
                    <h1 class="pdf-title" id="reportTitle">Loading Report...</h1>
                    <p class="pdf-subtitle">Inventory Management System</p>
                </div>
                
                <div class="pdf-info">
                    <p><strong>Date Range:</strong> <span id="dateRange">Loading...</span></p>
                    <p><strong>Location:</strong> <span id="locationInfo">Loading...</span></p>
                    <p><strong>Report Type:</strong> <span id="reportType">Loading...</span></p>
                </div>
                
                <div class="pdf-table-container">
                    <div class="loading" id="tableLoading">
                        <i class="fas fa-spinner fa-spin"></i> Loading report data...
                    </div>
                    <table class="pdf-table" id="reportTable" style="display: none;">
                        <thead id="tableHeader">
                            <!-- Table headers will be inserted here -->
                        </thead>
                        <tbody id="tableBody">
                            <!-- Table data will be inserted here -->
                        </tbody>
                    </table>
                </div>
                
                <div class="pdf-footer">
                    Report generated on: <span id="generationDate"><?= date('d/m/Y H:i:s') ?></span> | Inventory Management System
                </div>
            </div>
        </div>
        
        <div class="pagination">
            <button class="page-btn" id="prevPage">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="page-info">Page <span id="currentPage">1</span> of <span id="totalPages">1</span></div>
            <button class="page-btn" id="nextPage">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <script>
        // Global variables
        let currentPage = 1;
        let totalPages = 1;
        let reportData = [];
        const itemsPerPage = 10;
        let reportType = '';

        // DOM elements
        const reportTitle = document.getElementById('reportTitle');
        const dateRange = document.getElementById('dateRange');
        const locationInfo = document.getElementById('locationInfo');
        const reportTypeSpan = document.getElementById('reportType');
        const tableLoading = document.getElementById('tableLoading');
        const reportTable = document.getElementById('reportTable');
        const tableHeader = document.getElementById('tableHeader');
        const tableBody = document.getElementById('tableBody');
        const currentPageSpan = document.getElementById('currentPage');
        const totalPagesSpan = document.getElementById('totalPages');
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');
        const backButton = document.getElementById('backButton');
        const printButton = document.getElementById('printButton');
        const downloadButton = document.getElementById('downloadButton');

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Get report criteria from URL parameters or session
            const urlParams = new URLSearchParams(window.location.search);
            const preview = urlParams.get('preview');
            
            if (preview === 'true') {
                // Fetch report data from session
                fetchReportData();
            } else {
                // Show error if no preview parameter
                showError('No report data available for preview.');
            }
            
            // Set up event listeners
            setupEventListeners();
        });

        // Set up event listeners
        function setupEventListeners() {
            backButton.addEventListener('click', goBack);
            printButton.addEventListener('click', printReport);
            downloadButton.addEventListener('click', downloadReport);
            prevPageBtn.addEventListener('click', goToPrevPage);
            nextPageBtn.addEventListener('click', goToNextPage);
            
            // Adjust table on window resize
            window.addEventListener('resize', adjustTableColumns);
        }

        // Fetch report data (using session data from report.php)
        function fetchReportData() {
            // Use AJAX to get the actual report data from server
            fetch('get_report_data.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Process the received data
                    processReportData(data);
                })
                .catch(error => {
                    console.error('Error fetching report data:', error);
                    showError('Failed to load report data: ' + error.message);
                });
        }

        // Process the report data and render the preview
        function processReportData(data) {
            // Hide loading indicator
            tableLoading.style.display = 'none';
            reportTable.style.display = 'table';
            
            // Set report metadata
            reportType = data.report_type;
            reportTypeSpan.textContent = getReportTypeName(data.report_type);
            dateRange.textContent = formatDate(data.start_date) + ' - ' + formatDate(data.end_date);
            locationInfo.textContent = data.location_name || 'All Locations';
            reportTitle.textContent = getReportTitle(data.report_type);
            
            // Store the data for pagination
            reportData = data.data;
            totalPages = Math.ceil(reportData.length / itemsPerPage);
            totalPagesSpan.textContent = totalPages;
            
            // Generate table headers based on report type
            generateTableHeaders(data.report_type);
            
            // Render the first page
            renderPage(1);
            
            // Adjust table columns
            adjustTableColumns();
        }

        // Generate table headers based on report type
        function generateTableHeaders(reportType) {
            let headers = '';
            
            if (reportType === 'stock_in' || reportType === 'stock_out') {
                headers = `
                    <tr>
                        <th>No.</th>
                        <th>Item Code</th>
                        <th>Category</th>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Item Name</th>
                        <th>Quantity</th>
                        <th>Action</th>
                        <th>Unit</th>
                        <th>Location</th>
                        <th>Remarks</th>
                        <th>Action By</th>
                    </tr>
                `;
            } else if (reportType === 'stock_transfer') {
                headers = `
                    <tr>
                        <th>No.</th>
                        <th>Item Code</th>
                        <th>Category</th>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Item Name</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                        <th>From Location</th>
                        <th>To Location</th>
                        <th>Remarks</th>
                        <th>Action By</th>
                    </tr>
                `;
            } else if (reportType === 'repair') {
                headers = `
                    <tr>
                        <th>No.</th>
                        <th>Item Code</th>
                        <th>Category</th>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Item Name</th>
                        <th>Quantity</th>
                        <th>Action</th>
                        <th>Unit</th>
                        <th>From Location</th>
                        <th>To Location</th>
                        <th>Remarks</th>
                        <th>Action By</th>
                        <th>History Action</th>
                    </tr>
                `;
            }
            
            tableHeader.innerHTML = headers;
        }

        // Render a specific page of data
        function renderPage(page) {
            currentPage = page;
            currentPageSpan.textContent = currentPage;
            
            // Calculate start and end indices
            const startIndex = (page - 1) * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, reportData.length);
            
            // Clear previous data
            tableBody.innerHTML = '';
            
            // Add data for current page
            for (let i = startIndex; i < endIndex; i++) {
                const item = reportData[i];
                const row = createTableRow(item, i + 1);
                tableBody.appendChild(row);
            }
            
            // Update pagination buttons
            updatePaginationButtons();
        }

        // Create a table row for an item
        function createTableRow(item, index) {
            const row = document.createElement('tr');
            
            if (reportType === 'stock_in' || reportType === 'stock_out') {
                row.innerHTML = `
                    <td>${index}</td>
                    <td>${item.item_code}</td>
                    <td>${item.category_name}</td>
                    <td>${item.invoice_no}</td>
                    <td>${formatDate(item.date)}</td>
                    <td>${item.name}</td>
                    <td>${item.action_quantity}</td>
                    <td>${capitalizeFirstLetter(item.action_type)}</td>
                    <td>${item.size}</td>
                    <td>${item.location_name}</td>
                    <td>${item.remark}</td>
                    <td>${item.action_by_name}</td>
                `;
            } else if (reportType === 'stock_transfer') {
                row.innerHTML = `
                    <td>${index}</td>
                    <td>${item.item_code}</td>
                    <td>${item.category_name}</td>
                    <td>${item.invoice_no}</td>
                    <td>${formatDate(item.date)}</td>
                    <td>${item.name}</td>
                    <td>${item.quantity}</td>
                    <td>${item.size}</td>
                    <td>${item.from_location_name}</td>
                    <td>${item.to_location_name}</td>
                    <td>${item.remark}</td>
                    <td>${item.action_by_name}</td>
                `;
            } else if (reportType === 'repair') {
                row.innerHTML = `
                    <td>${index}</td>
                    <td>${item.item_code}</td>
                    <td>${item.category_name}</td>
                    <td>${item.invoice_no}</td>
                    <td>${formatDate(item.date)}</td>
                    <td>${item.item_name}</td>
                    <td>${item.quantity}</td>
                    <td>${item.action_type === 'send_for_repair' ? 'Send for Repair' : 'Returned'}</td>
                    <td>${item.size}</td>
                    <td>${item.from_location_name}</td>
                    <td>${item.to_location_name}</td>
                    <td>${item.remark}</td>
                    <td>${item.action_by_name}</td>
                    <td>${item.history_action}</td>
                `;
            }
            
            return row;
        }

        // Update pagination buttons state
        function updatePaginationButtons() {
            prevPageBtn.disabled = currentPage === 1;
            nextPageBtn.disabled = currentPage === totalPages;
        }

        // Go to previous page
        function goToPrevPage() {
            if (currentPage > 1) {
                renderPage(currentPage - 1);
            }
        }

        // Go to next page
        function goToNextPage() {
            if (currentPage < totalPages) {
                renderPage(currentPage + 1);
            }
        }

        // Go back to report generation page
        function goBack() {
            window.history.back();
        }

        // Print the report
        function printReport() {
            window.print();
        }

        // Download the report
        function downloadReport() {
            // This would trigger your PHP download functionality
            window.location.href = 'report.php?download=true';
        }

        // Make the table responsive by adjusting column widths
        function adjustTableColumns() {
            const container = document.querySelector('.pdf-table-container');
            const containerWidth = container.clientWidth;
            
            // Adjust font size for mobile
            if (containerWidth < 600) {
                reportTable.style.fontSize = '11px';
            } else {
                reportTable.style.fontSize = '13px';
            }
        }

        // Helper function to format date
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB'); // DD/MM/YYYY format
        }

        // Helper function to capitalize first letter
        function capitalizeFirstLetter(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        // Helper function to get report type name
        function getReportTypeName(type) {
            const types = {
                'stock_in': 'Stock In Report',
                'stock_out': 'Stock Out Report',
                'stock_transfer': 'Stock Transfer Report',
                'repair': 'Repair Report'
            };
            return types[type] || 'Report';
        }

        // Helper function to get report title
        function getReportTitle(type) {
            const titles = {
                'stock_in': 'Stock In Report',
                'stock_out': 'Stock Out Report',
                'stock_transfer': 'Stock Transfer Report',
                'repair': 'Repair Report'
            };
            return titles[type] || 'Inventory Report';
        }

        // Show error message
        function showError(message) {
            tableLoading.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
        }
    </script>
</body>
</html>