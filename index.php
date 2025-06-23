<?php

 $phpErrors = [];
 $patchName = 'module_patch';

 if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $patchName = $_POST['patch_name'] ?? 'module_patch';
  $uploadDir = sys_get_temp_dir() . '/patchgen/';

  if (!file_exists($uploadDir)) {
   mkdir($uploadDir, 0755, true);
  }

  $patchContent = '';
  $filePairs = [];

  foreach ($_FILES['file_pairs']['name'] as $index => $names) {
   $originalName = basename($_FILES['file_pairs']['name'][$index]['original'] ?? '');
   $modifiedName = basename($_FILES['file_pairs']['name'][$index]['modified'] ?? '');
   $customPath = trim($_POST['file_pairs'][$index]['path'] ?? '');
   if (!empty($_FILES['file_pairs']['name'][$index]['original'])) {
    if (empty($_FILES['file_pairs']['name'][$index]['modified'])) {
     $phpErrors[] = "Modified file is required for original file: {$originalName}";
     continue;
    }
    if (empty($customPath)) {
     $phpErrors[] = "Target path is required for file: {$originalName}";
     continue;
    }
    if ($originalName !== $modifiedName) {
     $phpErrors[] = "Filenames must match: '{$originalName}' and '{$modifiedName}'";
     continue;
    }
    $originalTmp = $_FILES['file_pairs']['tmp_name'][$index]['original'];
    $modifiedTmp = $_FILES['file_pairs']['tmp_name'][$index]['modified'];
    $originalPath = $uploadDir . uniqid('orig_') . '_' . $originalName;
    $modifiedPath = $uploadDir . uniqid('mod_') . '_' . $modifiedName;

    if (move_uploaded_file($originalTmp, $originalPath) && move_uploaded_file($modifiedTmp, $modifiedPath)) {
     $filePairs[] = [
      'original' => $originalPath,
      'modified' => $modifiedPath,
      'path' => rtrim($customPath, '/'),
      'filename' => $originalName
     ];
    } else {
     $phpErrors[] = "Failed to process files: {$originalName}";
    }
   }
  }
  if (empty($phpErrors)) {
   foreach ($filePairs as $pair) {
    $original = escapeshellarg($pair['original']);
    $modified = escapeshellarg($pair['modified']);
    $command = "git diff --no-index {$original} {$modified} 2>&1";
    $output = shell_exec($command);
    if ($output !== null) {
     $newPath = $pair['path'] . '/' . $pair['filename'];
     $cleanOutput = preg_replace([
      '/^diff --git a\K.+?(?= b)/m',
      '/^diff --git a.+? b\K.+$/m',
      '/^--- a\K.+$/m',
      '/^\+\+\+ b\K.+$/m'
     ], [
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
   if (!empty($patchContent)) {
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
    $phpErrors[] = "No differences found between the provided files. No patch was generated.";
   }
  }
 }

?>
<!DOCTYPE html>
<html lang="en">
 <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patch Generator Pro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
   .tooltip {
    position: relative;
    display: inline-block;
   }
   .tooltip-content {
    visibility: hidden; width: 300px; background-color: #1f2937;
    color: white; text-align: left; border-radius: 6px; padding: 8px 12px;
    font-size: 12px; line-height: 1.4; position: absolute; z-index: 1000;
    bottom: 125%; left: 50%; margin-left: -150px; opacity: 0;
    transition: opacity 0.3s; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
   }
   .tooltip-content::after {
    content: ""; position: absolute; top: 100%; left: 50%; margin-left: -5px;
    border-width: 5px; border-style: solid; border-color: #1f2937 transparent transparent transparent;
   }
   .tooltip:hover .tooltip-content { visibility: visible; opacity: 1; }
   .alert-modal {
    display: none; position: fixed; z-index: 9999; left: 0; top: 0;
    width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
    animation: fadeIn 0.3s;
   }
   .alert-content {
    background-color: #fefefe; margin: 10% auto; padding: 0; border-radius: 8px;
    width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.25);
    animation: slideIn 0.3s;
   }
   @keyframes fadeIn { from {opacity: 0;} to {opacity: 1;} }
   @keyframes slideIn { from {transform: translateY(-50px); opacity: 0;} to {transform: translateY(0); opacity: 1;} }
  </style>
 </head>
 <body class="bg-slate-100 min-h-screen">
  <div class="container mx-auto py-10 px-4 max-w-4xl">
   
   <h1 class="text-4xl font-bold text-slate-800 mb-8 text-center">Patch File Generator</h1>
   
   <form method="post" enctype="multipart/form-data" class="bg-white rounded-lg shadow-lg p-8" id="patch-form" novalidate>
    <div class="mb-8">
     <label for="patch_name" class="block text-sm font-medium text-slate-700 mb-1">
      Patch Name <span class="text-red-500">*</span>
      <div class="tooltip inline-block ml-1">
       <svg class="w-4 h-4 text-slate-400 hover:text-slate-600 cursor-help" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path></svg>
       <div class="tooltip-content">The name for the generated .patch file. Allowed characters: letters, numbers, hyphens (-), and underscores (_).</div>
      </div>
     </label>
     <input type="text" id="patch_name" name="patch_name" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="my-awesome-patch" value="<?= htmlspecialchars($patchName) ?>" required>
    </div>
    
    <div id="file-pairs-container">
     </div>
    
    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4 mt-8 pt-6 border-t border-slate-200">
     <button type="button" id="add-pair" class="w-full sm:w-auto flex items-center justify-center px-4 py-2 bg-slate-200 text-slate-800 rounded-md hover:bg-slate-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 transition-colors">
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
      Add File Pair
     </button>
     <button type="submit" class="w-full sm:w-auto flex-grow flex items-center justify-center px-6 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path></svg>
      Generate Patch File
     </button>
    </div>
   </form>

   <div id="alertModal" class="alert-modal">
    <div class="alert-content">
     <div class="bg-red-50 p-5 rounded-t-lg border-b border-red-200">
      <div class="flex items-center">
       <div class="flex-shrink-0"><svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg></div>
       <div class="ml-3"><h3 class="text-lg font-medium text-red-800">Validation Errors</h3></div>
      </div>
     </div>
     <div class="p-6">
      <div class="text-sm text-red-700" id="alertMessage"></div>
      <div class="mt-6 flex justify-end">
       <button type="button" id="closeAlert" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">Got it</button>
      </div>
     </div>
    </div>
   </div>

  </div>

  <script>

   document.addEventListener('DOMContentLoaded', function() {
    
    const container = document.getElementById('file-pairs-container');
    const addPairBtn = document.getElementById('add-pair');
    const form = document.getElementById('patch-form');
    let pairCounter = 0;

    const createFileInputHTML = (type, index) => {
        const title = type.charAt(0).toUpperCase() + type.slice(1);
        return `
            <div class="file-input-wrapper">
                <label class="block text-sm font-medium text-slate-700 mb-1">${title} File <span class="text-red-500">*</span></label>
                <div class="mt-1 flex justify-center items-center px-6 pt-5 pb-6 border-2 border-slate-300 border-dashed rounded-md">
                    <div class="space-y-1 text-center">
                        <svg class="mx-auto h-10 w-10 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true"><path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        <div class="flex text-sm text-slate-600">
                            <label for="file_pairs_${index}_${type}" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                <span>Upload a file</span>
                                <input id="file_pairs_${index}_${type}" name="file_pairs[${index}][${type}]" type="file" class="sr-only file-input ${type}-file" required>
                            </label>
                        </div>
                        <p class="text-xs text-slate-500 file-name-display">No file chosen</p>
                    </div>
                </div>
            </div>
        `;
    };
    
    const addNewPair = () => {
     const newPair = document.createElement('div');
     newPair.className = 'file-pair-group mb-6 p-5 border ring-1 ring-slate-200 rounded-md bg-slate-50 relative';
     const currentIndex = pairCounter;
     
     newPair.innerHTML = `
      <div class="flex justify-between items-center mb-4">
       <h3 class="text-lg font-medium text-slate-800">File Pair #${currentIndex + 1}</h3>
       <button type="button" class="remove-pair text-slate-500 hover:text-red-600 p-1 rounded-full hover:bg-red-100 transition-colors" title="Remove this file pair">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
       </button>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
        ${createFileInputHTML('original', currentIndex)}
        ${createFileInputHTML('modified', currentIndex)}
      </div>
      <div>
       <label class="block text-sm font-medium text-slate-700 mb-1">
        Target Path <span class="text-red-500">*</span>
        <div class="tooltip inline-block ml-1">
         <svg class="w-4 h-4 text-slate-400 hover:text-slate-600 cursor-help" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path></svg>
         <div class="tooltip-content">The directory path for this file in the target system. Example: "vendor/package/src" or "app/code/Module/User"</div>
        </div>
       </label>
       <input type="text" name="file_pairs[${currentIndex}][path]" class="target-path w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="vendor/magento/module-catalog" required>
      </div>
     `;
     container.appendChild(newPair);
     pairCounter++;
     updateUIAfterChange();
    };

    const updateUIAfterChange = () => {
        const allPairs = document.querySelectorAll('.file-pair-group');
        allPairs.forEach((group, index) => {
            group.querySelector('h3').textContent = `File Pair #${index + 1}`;
        });
        const removeButtons = document.querySelectorAll('.remove-pair');
        removeButtons.forEach(btn => {
            btn.disabled = allPairs.length <= 1;
            btn.style.opacity = allPairs.length <= 1 ? '0.5' : '1';
        });
    };
    container.addEventListener('change', function(e) {
        if (e.target.classList.contains('file-input')) {
            const display = e.target.closest('.space-y-1').querySelector('.file-name-display');
            display.textContent = e.target.files.length > 0 ? e.target.files[0].name : 'No file chosen';
        }
    });
    addPairBtn.addEventListener('click', addNewPair);
    container.addEventListener('click', function(e) {
     const removeBtn = e.target.closest('.remove-pair');
     if(removeBtn && !removeBtn.disabled) {
       removeBtn.closest('.file-pair-group').remove();
       updateUIAfterChange();
     }
    });

    form.addEventListener('submit', function(e) {
     let isValid = true;
     const errorMessages = [];
     
     const patchName = document.getElementById('patch_name');
     if (!patchName.value.trim()) {
      errorMessages.push('Patch name is required.');
      isValid = false;
     } else if (!/^[a-zA-Z0-9_-]+$/.test(patchName.value.trim())) {
      errorMessages.push('Patch name has invalid characters. Use only letters, numbers, hyphens, and underscores.');
      isValid = false;
     }
     
     document.querySelectorAll('.file-pair-group').forEach((group, index) => {
      const originalFile = group.querySelector('.original-file');
      const modifiedFile = group.querySelector('.modified-file');
      const targetPath = group.querySelector('.target-path');
      
      if (!targetPath.value.trim()) {
        errorMessages.push(`Target path is required for file pair #${index + 1}.`);
        isValid = false;
      }
      
      if (originalFile.files.length === 0) {
        errorMessages.push(`Original file is missing for file pair #${index + 1}.`);
        isValid = false;
      }

      if (modifiedFile.files.length === 0) {
        errorMessages.push(`Modified file is missing for file pair #${index + 1}.`);
        isValid = false;
      }
      
      if (originalFile.files.length > 0 && modifiedFile.files.length > 0) {
       if (originalFile.files[0].name !== modifiedFile.files[0].name) {
        errorMessages.push(`Filenames must match in file pair #${index + 1}: "${originalFile.files[0].name}" vs "${modifiedFile.files[0].name}".`);
        isValid = false;
       }
      }
     });
     
     if (!isValid) {
      e.preventDefault();
      showAlert(errorMessages);
     }
    });

    const modal = document.getElementById('alertModal');
    const closeBtn = document.getElementById('closeAlert');

    window.showAlert = function(messages) {
     const messageDiv = document.getElementById('alertMessage');
     messageDiv.innerHTML = `<ul class="list-disc pl-5 space-y-1">${messages.map(m => `<li>${m}</li>`).join('')}</ul>`;
     modal.style.display = 'block';
     setTimeout(() => closeBtn.focus(), 100);
    }
    
    const closeModal = () => { modal.style.display = 'none'; };
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    addNewPair();

   });
  </script>

  <?php
    if (!empty($phpErrors)) :
  ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
        showAlert(<?= json_encode($phpErrors) ?>);
    });
  </script>
  <?php endif; ?>

 </body>
</html>
