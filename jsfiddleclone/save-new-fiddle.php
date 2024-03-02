<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'];
    $name = $_POST['name'];

    // Generate the file name
    $date = date('Y-m-d');
    $name = preg_replace('/[^\w\s]/', '', $name); // Remove illegal characters
    $name = str_replace(' ', '-', $name); // Replace spaces with "-"

    $filename = $date . '-' . $name . '.html';

    // Check if the "html" folder exists
    if (!is_dir('htmls')) {
        // Create the "htmls" folder if it doesn't exist
        mkdir('html');
    }

    // Save the content to the file
    $filepath = 'htmls/' . $filename;
    file_put_contents($filepath, $code);

    echo "Code saved successfully!";
}
?>
<body>
    <form method="post" action="">
        <label for="code">Code:</label><br>
        <textarea name="code" rows="10" cols="50"></textarea><br><br>

        <label for="name">Name:</label><br>
        <input type="text" name="name"><br><br>

        <input type="submit" value="Save Code">
    </form>
</body>
</html>