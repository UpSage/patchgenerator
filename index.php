<?php

 if($_SERVER['REQUEST_METHOD'] === 'POST'){

  $errors = [];
  $patchName = $_POST['patch_name'] ?? 'module_patch';
  $uploadDir = sys_get_temp_dir() . '/patchgen/';
  
  if(!file_exists($uploadDir)){
   mkdir($uploadDir, 0755, true);
  }
  
  $patchContent = '';
  $filePairs = [];
  
  foreach($_FILES['file_pairs']['name'] as $index => $names){

   $originalName = basename($_FILES['file_pairs']['name'][$index]['original'] ?? '');
   $modifiedName = basename($_FILES['file_pairs']['name'][$index]['modified'] ?? '');
   $customPath = trim($_POST['file_pairs'][$index]['path'] ?? '');
   
   if(!empty($_FILES['file_pairs']['name'][$index]['original'])){
    if(empty($_FILES['file_pairs']['name'][$index]['modified'])){
     $errors[] = "Modified file is required for original file: {$originalName}";
     continue;
    }
    if(empty($customPath)){
     $errors[] = "Target path is required for file: {$originalName}";
     continue;
    }
    if($originalName !== $modifiedName){
     $errors[] = "Filenames must match: '{$originalName}' and '{$modifiedName}'";
     continue;
    }
    $originalTmp = $_FILES['file_pairs']['tmp_name'][$index]['original'];
    $modifiedTmp = $_FILES['file_pairs']['tmp_name'][$index]['modified'];
    $originalPath = $uploadDir . uniqid('orig_') . '_' . $originalName;
    $modifiedPath = $uploadDir . uniqid('mod_') . '_' . $modifiedName;
    if(move_uploaded_file($originalTmp, $originalPath) && move_uploaded_file($modifiedTmp, $modifiedPath)){
     $filePairs[] = [
      'original' => $originalPath,
      'modified' => $modifiedPath,
      'path' => rtrim($customPath, '/'),
      'filename' => $originalName
     ];
    } else {
     $errors[] = "Failed to process files: {$originalName}";
    }
   }

  }
  
  if(empty($errors)){
   foreach($filePairs as $pair){
    
    $original = escapeshellarg($pair['original']);
    $modified = escapeshellarg($pair['modified']);
    
    $command = "git diff --no-index {$original} {$modified} 2>&1";
    $output = shell_exec($command);
    
    if($output !== null){
     $newPath = $pair['path'] . '/' . $pair['filename'];
     $cleanOutput = preg_replace([
      '/^diff --git a\K.+?(?= b)/m',
      '/^diff --git a.+? b\K.+$/m',
      '/^--- a\K.+$/m',
      '/^\+\+\+ b\K.+$/m'
     ],[
      '/' . $newPath,
      '/' . $newPath,
      '/' . $newPath,
      '/' . $newPath
     ], $output);
     $patchContent .= $cleanOutput . "\n";
    }
    
    @unlink($pair['original']);
    @unlink($pair['modified']);

   }
   
   if(!empty($patchContent)){
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $patchName) . '.patch';
    $tempFile = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tempFile, $patchContent);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tempFile));
    readfile($tempFile);
    @unlink($tempFile);
    exit;
   } else {
    $errors[] = "No valid patch content was generated";
   }
  }

 }

?>

<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patch Generator</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
   .tooltip {
    position: relative;
    display: inline-block;
   }
   
   .tooltip-content {
    visibility: hidden;
    width: 300px;
    background-color: #1f2937;
    color: white;
    text-align: left;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 12px;
    line-height: 1.4;
    position: absolute;
    z-index: 1000;
    bottom: 125%;
    left: 50%;
    margin-left: -150px;
    opacity: 0;
    transition: opacity 0.3s;
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
   }
   
   .tooltip-content::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -5px;
    border-width: 5px;
    border-style: solid;
    border-color: #1f2937 transparent transparent transparent;
   }
   
   .tooltip:hover .tooltip-content {
    visibility: visible;
    opacity: 1;
   }
   
   .alert-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
    animation: fadeIn 0.3s;
   }
   
   .alert-content {
    background-color: #fefefe;
    margin: 15% auto;
    padding: 0;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
    animation: slideIn 0.3s;
   }
   
   @keyframes fadeIn {
    from {opacity: 0;}
    to {opacity: 1;}
   }
   
   @keyframes slideIn {
    from {transform: translateY(-50px); opacity: 0;}
    to {transform: translateY(0); opacity: 1;}
   }
  </style>
 </head>
 <body class="bg-gray-100 min-h-screen">
  <div class="container mx-auto py-8 px-4 max-w-3xl">
   
   <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">Patch File Generator</h1>
   
   <?php if(!empty($errors)): ?>
   <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
    <div class="flex">
     <div class="flex-shrink-0">
      <svg class="h-5 w-5 text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
       <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
      </svg>
     </div>
     <div class="ml-3">
      <h3 class="text-sm font-medium text-red-800">There were errors with your submission:</h3>
      <div class="mt-2 text-sm text-red-700">
       <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
       </ul>
      </div>
     </div>
    </div>
   </div>
   <?php endif; ?>
   
   <form method="post" enctype="multipart/form-data" class="bg-white rounded-lg shadow-md p-6" id="patch-form">
    <div class="mb-6">
     <label for="patch_name" class="block text-sm font-medium text-gray-700 mb-1">
      Patch Name <span class="text-red-500">*</span>
      <div class="tooltip inline-block ml-1">
       <svg class="w-4 h-4 text-gray-400 hover:text-gray-600 cursor-help" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path>
       </svg>
       <div class="tooltip-content">
        The name of the generated patch file (without extension). Only alphanumeric characters, hyphens, and underscores are allowed.
       </div>
      </div>
     </label>
     <input type="text" id="patch_name" name="patch_name" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="module_patch" value="<?= htmlspecialchars($patchName) ?>" required>
    </div>
    
    <div id="file-pairs-container">
     <div class="file-pair-group mb-6 p-4 border border-gray-200 rounded-md bg-gray-50">
      <div class="flex justify-between items-center mb-4">
       <h3 class="text-lg font-medium text-gray-700">File Pair #1</h3>
       <button type="button" class="remove-pair text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100 transition-colors" title="Remove this file pair">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
        </svg>
       </button>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
       <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Original File</label>
        <input type="file" name="file_pairs[0][original]" class="original-file block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
       </div>
       <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Modified File</label>
        <input type="file" name="file_pairs[0][modified]" class="modified-file block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
       </div>
      </div>
      
      <div>
       <label class="block text-sm font-medium text-gray-700 mb-2">
        Target Path <span class="text-red-500">*</span>
        <div class="tooltip inline-block ml-1">
         <svg class="w-4 h-4 text-gray-400 hover:text-gray-600 cursor-help" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path>
         </svg>
         <div class="tooltip-content">
          The directory path where this file should be located in the target system. For example: "vendor/package/src" or "app/modules/user"
         </div>
        </div>
       </label>
       <input type="text" name="file_pairs[0][path]" class="target-path w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="vendor/package/src" required>
      </div>
     </div>
    </div>
    
    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4">
     <button type="button" id="add-pair" class="flex items-center justify-center px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors">
      <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
      </svg>
      Add Another File Pair
     </button>
     <button type="submit" class="flex items-center justify-center px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
      <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
      </svg>
      Generate Patch File
     </button>
    </div>
   </form>

   <!-- Custom Alert Modal -->
   <div id="alertModal" class="alert-modal">
    <div class="alert-content">
     <div class="bg-red-50 p-6 rounded-t-lg border-b border-red-200">
      <div class="flex items-center">
       <div class="flex-shrink-0">
        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
       </div>
       <div class="ml-3">
        <h3 class="text-lg font-medium text-red-800">Validation Errors</h3>
       </div>
      </div>
     </div>
     <div class="p-6">
      <div class="text-sm text-red-700" id="alertMessage"></div>
      <div class="mt-6 flex justify-end">
       <button type="button" id="closeAlert" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
        Got it
       </button>
      </div>
     </div>
    </div>
   </div>

  </div>
  <script>
   document.addEventListener('DOMContentLoaded', function() {
    
    const container = document.getElementById('file-pairs-container');
    let pairCounter = 1;
    
    function updateFilePairNumbers() {
     document.querySelectorAll('.file-pair-group').forEach((group, index) => {
      const header = group.querySelector('h3');
      header.textContent = `File Pair #${index + 1}`;
     });
    }
    
    document.getElementById('add-pair').addEventListener('click', function() {
     const newPair = document.createElement('div');
     newPair.className = 'file-pair-group mb-6 p-4 border border-gray-200 rounded-md bg-gray-50';
     newPair.innerHTML = `
      <div class="flex justify-between items-center mb-4">
       <h3 class="text-lg font-medium text-gray-700">File Pair #${pairCounter + 1}</h3>
       <button type="button" class="remove-pair text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100 transition-colors" title="Remove this file pair">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
         <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
        </svg>
       </button>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
       <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Original File</label>
        <input type="file" name="file_pairs[${pairCounter}][original]" class="original-file block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
       </div>
       <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Modified File</label>
        <input type="file" name="file_pairs[${pairCounter}][modified]" class="modified-file block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
       </div>
      </div>
      
      <div>
       <label class="block text-sm font-medium text-gray-700 mb-2">
        Target Path <span class="text-red-500">*</span>
        <div class="tooltip inline-block ml-1">
         <svg class="w-4 h-4 text-gray-400 hover:text-gray-600 cursor-help" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path>
         </svg>
         <div class="tooltip-content">
          The directory path where this file should be located in the target system. For example: "vendor/package/src" or "app/modules/user"
         </div>
        </div>
       </label>
       <input type="text" name="file_pairs[${pairCounter}][path]" class="target-path w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="vendor/package/src" required>
      </div>
     `;
     container.appendChild(newPair);
     pairCounter++;
     updateFilePairNumbers();
    });
    
    container.addEventListener('click', function(e) {
     if(e.target.closest('.remove-pair')) {
      const filePairGroups = document.querySelectorAll('.file-pair-group');
      if(filePairGroups.length > 1) {
       e.target.closest('.file-pair-group').remove();
       updateFilePairNumbers();
      }
     }
    });
    
    document.getElementById('patch-form').addEventListener('submit', function(e) {

     let isValid = true;
     const errorMessages = [];
     
     // Validate patch name
     const patchName = document.getElementById('patch_name');
     if(!patchName.value.trim()) {
      patchName.classList.add('border-red-500');
      isValid = false;
      errorMessages.push('Patch name is required');
     } else if(!/^[a-zA-Z0-9_-]+$/.test(patchName.value.trim())) {
      patchName.classList.add('border-red-500');
      isValid = false;
      errorMessages.push('Patch name can only contain letters, numbers, hyphens, and underscores');
     } else {
      patchName.classList.remove('border-red-500');
     }
     
     document.querySelectorAll('.file-pair-group').forEach((group, index) => {
      const originalFile = group.querySelector('.original-file');
      const modifiedFile = group.querySelector('.modified-file');
      const targetPath = group.querySelector('.target-path');
      
      if(!targetPath.value.trim()) {
       targetPath.classList.add('border-red-500');
       isValid = false;
       errorMessages.push(`Target path is required for file pair ${index + 1}`);
      } else {
       targetPath.classList.remove('border-red-500');
      }
      
      if (originalFile.files.length > 0 && modifiedFile.files.length > 0) {
       if (originalFile.files[0].name !== modifiedFile.files[0].name) {
        originalFile.classList.add('border-red-500');
        modifiedFile.classList.add('border-red-500');
        isValid = false;
        errorMessages.push(`Filenames must match in file pair ${index + 1}: "${originalFile.files[0].name}" and "${modifiedFile.files[0].name}"`);
       } else {
        originalFile.classList.remove('border-red-500');
        modifiedFile.classList.remove('border-red-500');
       }
      }
    
     });
     
     if(!isValid) {
      e.preventDefault();
      showAlert(errorMessages);
     }
    });

    // Alert modal functions
    function showAlert(messages) {
     const modal = document.getElementById('alertModal');
     const messageDiv = document.getElementById('alertMessage');
     
     let messageHtml = '<ul class="list-disc pl-5 space-y-1">';
     messages.forEach(message => {
      messageHtml += `<li>${message}</li>`;
     });
     messageHtml += '</ul>';
     
     messageDiv.innerHTML = messageHtml;
     modal.style.display = 'block';
     
     // Focus the close button for accessibility
     setTimeout(() => {
      document.getElementById('closeAlert').focus();
     }, 100);
    }
    
    document.getElementById('closeAlert').addEventListener('click', function() {
     document.getElementById('alertModal').style.display = 'none';
    });
    
    // Close modal when clicking outside
    document.getElementById('alertModal').addEventListener('click', function(e) {
     if (e.target === this) {
      this.style.display = 'none';
     }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
     if (e.key === 'Escape') {
      document.getElementById('alertModal').style.display = 'none';
     }
    });

   });
  </script>
 </body>
</html>