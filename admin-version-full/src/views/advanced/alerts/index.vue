<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Trigger Modal And Trigger Toast">
          <b-button
            type="button"
            variant="primary"
            size="sm"
            @click="() => executeExample('TriggerModalToast')"
          >
            Trigger modal
          </b-button>
          {{ " " }}
          <b-button
            type="button"
            variant="primary"
            size="sm"
            @click="() => executeExample('TriggerModalToast1')"
          >
            Trigger toast
          </b-button>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Icons">
          <b-button
            type="button"
            size="sm"
            variant="success"
            @click="() => executeExample('success')"
          >
            Success
          </b-button>
          {{ " " }}
          <b-button
            type="button"
            size="sm"
            variant="danger"
            @click="() => executeExample('error')"
          >
            Error
          </b-button>
          {{ " " }}
          <b-button
            type="button"
            size="sm"
            variant="warning"
            @click="() => executeExample('warning')"
          >
            Warning
          </b-button>
          {{ " " }}
          <b-button
            type="button"
            size="sm"
            variant="info"
            @click="() => executeExample('info')"
          >
            Info
          </b-button>
          {{ " " }}
          <b-button
            type="button"
            size="sm"
            variant="secondary"
            @click="() => executeExample('question')"
          >
            Question
          </b-button>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col cols="12">
        <UIComponentCard title="Examples">
          <b-table-simple responsive class="mb-0">
            <b-tbody>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-pink"></i>A basic
                  message</b-td
                >
                <b-td class="border-0">
                  <b-button
                    size="sm"
                    variant="primary"
                    @click="() => executeExample('basicMessage')"
                  >
                    Click me
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-success"></i>A
                  title with a text under</b-td
                >
                <b-td class="border-0">
                  <b-button
                    size="sm"
                    variant="primary"
                    @click="() => executeExample('titleText')"
                  >
                    Click me
                  </b-button>
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-warning"></i>A
                  modal with a title, an error icon, a text, and a footer</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('errorType')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-dark"></i>Custom
                  HTML description and buttons with ARIA labels</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('customHtml')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-pink"></i>A dialog
                  with three buttons</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('threeButtons')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-info"></i>A custom
                  positioned dialog</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('customPosition')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-primary"></i
                  >Custom animation with Animation</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('customAnimation')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-secondary"></i>A
                  confirm dialog, with a function attached to the
                  "Confirm"-button...</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('warningConfirm')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-danger"></i>...
                  and by passing a parameter, you can execute something else for
                  "Cancel".</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('handleDismiss')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-purple"></i>A
                  message with a custom image</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('customImage')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-success"></i>A
                  message with custom width, padding, background and animated
                  Nyan Cat</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('customWidth')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-warning"></i>A
                  message with auto close timer</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('timer')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-info"></i
                  >Right-to-left support for Arabic, Persian, Hebrew, and other
                  RTL languages</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('rtl')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-pink"></i>AJAX
                  request example</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('ajaxRequest')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-warning"></i>Mixin
                  example</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('mixin')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
              <b-tr class="border-0">
                <b-td class="border-0"
                  ><i class="mdi mdi-circle-outline me-1 text-warning"></i
                  >Declarative template example</b-td
                >
                <b-td class="border-0">
                  <b-button
                    type="button"
                    variant="primary"
                    size="sm"
                    @click="() => executeExample('declarativeTemplate')"
                    >Click me</b-button
                  >
                </b-td>
              </b-tr>
            </b-tbody>
          </b-table-simple>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>

<script setup lang="ts">
import Swal from "sweetalert2/dist/sweetalert2.js";

import logoSm from "@/assets/images/logo-sm.png";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const executeExample = (sub: any) => {
  switch (sub) {
    case "basicMessage":
      (Swal as TVueSwalInstance).fire("Any fool can use a computer");
      return;

    case "titleText":
      (Swal as TVueSwalInstance).fire(
        "The Internet?",
        "That thing is still around?",
        "question",
      );
      return;

    case "errorType":
      (Swal as TVueSwalInstance).fire({
        icon: "error",
        title: "Oops...",
        text: "Something went wrong!",
        footer: "<a href>Why do I have this issue?</a>",
      });
      return;

    case "customHtml":
      (Swal as TVueSwalInstance).fire({
        title: "<strong>HTML <u>example</u></strong>",
        icon: "info",
        html:
          "You can use <b>bold text</b>, " +
          '<a href="//sweetalert2.github.io">links</a> ' +
          "and other HTML tags",
        showCloseButton: true,
        showCancelButton: true,
        focusConfirm: false,
        confirmButtonText: '<i class="fa fa-thumbs-up"></i> Great!',
        confirmButtonAriaLabel: "Thumbs up, great!",
        cancelButtonText: '<i class="fa fa-thumbs-down"></i>',
        cancelButtonAriaLabel: "Thumbs down",
      });
      return;

    case "threeButtons":
      (Swal as TVueSwalInstance)
        .fire({
          title: "Do you want to save the changes?",
          showDenyButton: true,
          showCancelButton: true,
          confirmButtonText: `Save`,
          denyButtonText: `Don't save`,
        })
        .then((result: any) => {
          /* Read more about isConfirmed, isDenied below */
          if (result.isConfirmed) {
            (Swal as TVueSwalInstance).fire("Saved!", "", "success");
          } else if (result.isDenied) {
            (Swal as TVueSwalInstance).fire(
              "Changes are not saved",
              "",
              "info",
            );
          }
        });
      return;

    case "customPosition":
      (Swal as TVueSwalInstance).fire({
        position: "top-end",
        icon: "success",
        title: "Your work has been saved",
        showConfirmButton: false,
        timer: 1500,
      });
      return;

    case "customAnimation":
      (Swal as TVueSwalInstance).fire({
        title: "Custom animation with Animate.css",
        showClass: {
          popup: "animate__animated animate__fadeInDown",
        },
        hideClass: {
          popup: "animate__animated animate__fadeOutUp",
        },
      });
      return;

    case "warningConfirm":
      (Swal as TVueSwalInstance)
        .fire({
          title: "Are you sure?",
          text: "You won't be able to revert this!",
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#3085d6",
          cancelButtonColor: "#d33",
          confirmButtonText: "Yes, delete it!",
        })
        .then(function (result: any) {
          if (result.isConfirmed) {
            (Swal as TVueSwalInstance).fire(
              "Deleted!",
              "Your file has been deleted.",
              "success",
            );
          }
        });
      return;

    case "handleDismiss":
      const swalWithBootstrapButtons = (Swal as TVueSwalInstance).mixin({
        customClass: {
          confirmButton: "btn btn-success",
          cancelButton: "btn btn-danger me-2",
        },
        buttonsStyling: false,
      });

      swalWithBootstrapButtons
        .fire({
          title: "Are you sure?",
          text: "You won't be able to revert this!",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "Yes, delete it!",
          cancelButtonText: "No, cancel!",
          reverseButtons: true,
        })
        .then((result: any) => {
          if (result.isConfirmed) {
            swalWithBootstrapButtons.fire(
              "Deleted!",
              "Your file has been deleted.",
              "success",
            );
          } else if (
            /* Read more about handling dismissals below */
            result.dismiss === (Swal as TVueSwalInstance).DismissReason.cancel
          ) {
            swalWithBootstrapButtons.fire(
              "Cancelled",
              "Your imaginary file is safe :)",
              "error",
            );
          }
        });
      return;

    case "customImage":
      (Swal as TVueSwalInstance).fire({
        title: "Rizz!",
        text: "Modal with a Brand Logo.",
        imageUrl: logoSm,
        imageWidth: 80,
        imageHeight: 80,
        imageAlt: "Custom image",
      });
      return;

    case "customWidth":
      (Swal as TVueSwalInstance).fire({
        title: "Custom width, padding, background.",
        width: 600,
        padding: 50,
        background: `rgba(254,254,254,0.01)  url("assets/images/bg-body.jpg") `,
        backgroundSize: "cover",
        backgroundPosition: "center",
      });
      return;

    case "timer":
      let timerInterval: any;
      (Swal as TVueSwalInstance)
        .fire({
          title: "Auto close alert!",
          html: "I will close in <b></b> milliseconds.",
          timer: 2000,
          timerProgressBar: true,
          didOpen: () => {
            (Swal as TVueSwalInstance).showLoading();
            timerInterval = setInterval(() => {
              const content = (Swal as TVueSwalInstance).getContent();
              if (content) {
                const b = content.querySelector("b");
                if (b) {
                  b.textContent = (Swal as TVueSwalInstance).getTimerLeft();
                }
              }
            }, 100);
          },
          willClose: () => {
            clearInterval(timerInterval);
          },
        })
        .then((result: any) => {
          /* Read more about handling dismissals below */
          if (
            result.dismiss === (Swal as TVueSwalInstance).DismissReason.timer
          ) {
            console.log("I was closed by the timer");
          }
        });
      return;

    case "rtl":
      (Swal as TVueSwalInstance).fire({
        title: "هل تريد الاستمرار؟",
        icon: "question",
        iconHtml: "؟",
        confirmButtonText: "نعم",
        cancelButtonText: "لا",
        showCancelButton: true,
        showCloseButton: true,
      });
      return;

    case "ajaxRequest":
      (Swal as TVueSwalInstance)
        .fire({
          title: "Submit your Github username",
          input: "text",
          inputAttributes: {
            autocapitalize: "off",
          },
          showCancelButton: true,
          confirmButtonText: "Look up",
          showLoaderOnConfirm: true,
          preConfirm: (login: any) => {
            return fetch(`//api.github.com/users/${login}`)
              .then((response) => {
                if (!response.ok) {
                  throw new Error(response.statusText);
                }
                return response.json();
              })
              .catch((error) => {
                (Swal as TVueSwalInstance).showValidationMessage(
                  `Request failed: ${error}`,
                );
              });
          },
          allowOutsideClick: () => !(Swal as TVueSwalInstance).isLoading(),
        })
        .then((result: any) => {
          if (result.isConfirmed) {
            (Swal as TVueSwalInstance).fire({
              title: `${result.value.login}'s avatar`,
              imageUrl: result.value.avatar_url,
            });
          }
        });
      return;

    case "chainingModals":
      (Swal as TVueSwalInstance)
        .mixin({
          input: "text",
          confirmButtonText: "Next &rarr;",
          showCancelButton: true,
          progressSteps: ["1", "2", "3"],
        })
        .queue([
          {
            title: "Question 1",
            text: "Chaining swal2 modals is easy",
          },
          "Question 2",
          "Question 3",
        ])
        .then((result: any) => {
          if (result.value) {
            let answers = JSON.stringify(result.value);
            (Swal as TVueSwalInstance).fire({
              title: "All done!",
              html: `
                  Your answers:
                  <pre><code>${answers}</code></pre>
                `,
              confirmButtonText: "Lovely!",
            });
          }
        });
      return;

    case "dynamicQueue":
      const ipAPI = "//api.ipify.org?format=json";

      (Swal as TVueSwalInstance).queue([
        {
          title: "Your public IP",
          confirmButtonText: "Show my public IP",
          text: "Your public IP will be received " + "via AJAX request",
          showLoaderOnConfirm: true,
          preConfirm: () => {
            return fetch(ipAPI)
              .then((response) => response.json())
              .then((data) =>
                (Swal as TVueSwalInstance).insertQueueStep(data.ip),
              )
              .catch(() => {
                (Swal as TVueSwalInstance).insertQueueStep({
                  icon: "error",
                  title: "Unable to get your public IP",
                });
              });
          },
        },
      ]);
      return;

    case "mixin":
      const Toast = (Swal as TVueSwalInstance).mixin({
        toast: true,
        position: "top-end",
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast: any) => {
          toast.addEventListener(
            "mouseenter",
            (Swal as TVueSwalInstance).stopTimer,
          );
          toast.addEventListener(
            "mouseleave",
            (Swal as TVueSwalInstance).resumeTimer,
          );
        },
      });
      Toast.fire({
        icon: "success",
        title: "Signed in successfully",
      });
      return;

    case "declarativeTemplate":
      (Swal as TVueSwalInstance).fire({
        title: 'Save changes to "Untitled 1" before closing?',
        icon: "warning",
        iconColor: "red",
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: `Save As`,
        denyButtonText: `Close without Saving `,
      });
      return;

    case "TriggerModalToast":
      (Swal as TVueSwalInstance).fire({
        title: 'Save changes to "Untitled 1" before closing?',
        icon: "warning",
        iconColor: "red",
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: `Save As`,
        denyButtonText: `Close without Saving `,
      });
      return;

    case "TriggerModalToast1":
      (Swal as TVueSwalInstance).fire({
        toast: true,
        title: 'Save changes to "Untitled 1" before closing?',
        icon: "warning",
        iconColor: "red",
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: `Save As`,
        denyButtonText: `Close without Saving `,
      });
      return;

    case "success":
      (Swal as TVueSwalInstance).fire({
        icon: "success",
        title: "Your work has been saved",
        timer: 1500,
      });
      return;

    case "error":
      (Swal as TVueSwalInstance).fire({
        icon: "error",
        title: "Oops...",
        text: "Something went wrong!",
      });
      return;

    case "warning":
      (Swal as TVueSwalInstance).fire({
        icon: "warning",
        title: "Oops...",
        text: "Icon warning!",
      });
      return;

    case "info":
      (Swal as TVueSwalInstance).fire({
        icon: "info",
        title: "Oops...",
        text: "Icon Info!",
      });
      return;

    case "question":
      (Swal as TVueSwalInstance).fire({
        icon: "question",
        title: "Oops...",
        text: "Icon question!",
      });
      return;
  }
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
