<script>
// Check if "lowbandwidth" is present in the URL
if (window.location.href.includes("lowbandwidth")) {
  // Create a <style> element
  var style = document.createElement("style");
  // Define the CSS rules
  style.innerHTML = `
    object, img {
      display: none !important;
    }
  `;
  // Add the <style> element to the document's head
  document.head.appendChild(style);
}
</script>

<script>
function checkKeyword() {
  var e = document.getElementById("inputField").value;
  if (e.length === 4) {
    if (e === atob("NDg2OQ==")) {
      document.getElementById("inputField").style.display = "none";
      var newElement = document.createElement("p");
      newElement.innerHTML = decodeURIComponent(atob("PGEgaHJlZj0ic2F2ZS1uZXctZmlkZGxlLnBocCIgdGFyZ2V0PSJfYmxhbmsiPlNhdmUgTmV3IEhUTUw8L2E+"));
      document.body.appendChild(newElement);
    } else {
      document.getElementById("keywordForm").submit();
    }
  }
}
</script>
<form id="keywordForm" onsubmit="event.preventDefault(); checkKeyword()">
  Pass:-<input type="password" id="inputField" style="border:none;" placeholder="">-
</form> <a href="jsfiddle-user.php">[NORMAL PAGE]</a>





<style>
  .preview-container {
    border: 1px solid black;
    padding: 10px;
  }
</style>
<?php
$folderPath = 'htmls';
$files = glob($folderPath . '/*.html');
$fileCount = count($files);
$gridColumns = 6;
$gridRows = ceil($fileCount / $gridColumns);
?>

<div id="preview-container-wrapper"></div>

<script>
  // Function to append the preview containers to the wrapper div
  function appendPreviewContainers() {
    var wrapper = document.getElementById('preview-container-wrapper');
    <?php
$folderPath = 'htmls';
$files = glob($folderPath . '/*.html');
$fileCount = count($files);
$gridColumns = 6;
$gridRows = ceil($fileCount / $gridColumns);
$files = array_reverse($files); // Reverse the order of the files

for ($row = 0; $row < $gridRows; $row++) {
  echo 'var row' . $row . ' = document.createElement("div");';
  echo 'row' . $row . '.style.display = "flex";';

  for ($col = 0; $col < $gridColumns; $col++) {
    $index = $row * $gridColumns + $col;
    if ($index < $fileCount) {
      $file = $files[$index];
      $fileName = basename($file);
      $objectPreview = '<object data="' . $file . '" width="300" height="200" scrolling="no"></object>';

      echo 'var container' . $index . ' = document.createElement("div");';
      echo 'container' . $index . '.className = "preview-container";';
      echo 'container' . $index . '.style.flex = "1";';
      echo 'container' . $index . '.style.margin = "5px";';
      echo 'container' . $index . '.innerHTML = \'' . $objectPreview . '<br><a href="' . $file . '" target="_blank">' . $fileName . '</a>\';';

      echo 'row' . $row . '.appendChild(container' . $index . ');';
    }
  }

  echo 'wrapper.appendChild(row' . $row . ');';
}
?>
  }

  // Function to stop further data fetching after a specified delay
  function stopDataFetching() {
    var xhr = new XMLHttpRequest();
    xhr.abort();
  }

  // Call the function to append the preview containers
  appendPreviewContainers();

  // Wait for 20 seconds and then stop further data fetching
  setTimeout(stopDataFetching, 5000);
</script>


<a target="_blank" href="https://codepen.io/ryedai1/pens/public?grid_type=list">Codepen</a> <a target="_blank" href="https://jsfiddle.net/user/aoikurayami/fiddles/" style=color:blue>JSFiddle</a><br>
﻿<?php
    //Allow or disallow source viewing
    define("ALLOW_SOURCE",TRUE);
    define("ALLOW_TITLE",TRUE);
    if(ALLOW_SOURCE && isset($_GET['source'])){
        highlight_file(__FILE__);
        exit(0);
    }
?>
<a target="_blank" href="?source">Source Code</a>
