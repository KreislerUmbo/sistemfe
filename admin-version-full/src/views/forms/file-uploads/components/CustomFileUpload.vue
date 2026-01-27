<template>
  <div class="d-grid">
    <p class="text-muted">
      Upload your blog image here, Please click "Upload Image" Button.
    </p>
    <div
      class="preview-box d-block justify-content-center rounded border-dashed border-theme-color overflow-hidden p-3"
    ></div>
    <input
      type="file"
      id="input-file"
      name="input-file"
      accept="image/*"
      @change="handleChange()"
      hidden
    />
    <label class="btn-upload btn btn-primary mt-3" for="input-file"
      >Upload Image</label
    >
  </div>
</template>
<script setup lang="ts">
const handleChange = () => {
  const fileUploader = document.querySelector("#input-file") as any;
  const getFile = fileUploader.files;
  if (getFile.length !== 0) {
    const uploadedFile = getFile[0];
    readFile(uploadedFile);
  }
};

const readFile = (uploadedFile: any) => {
  if (uploadedFile) {
    const reader = new FileReader();
    reader.onload = function () {
      const parent = document.querySelector(".preview-box") as HTMLElement;
      parent.innerHTML = `<img class="preview-content" src=${reader.result} />`;
    };

    reader.readAsDataURL(uploadedFile);
  }
};
</script>
