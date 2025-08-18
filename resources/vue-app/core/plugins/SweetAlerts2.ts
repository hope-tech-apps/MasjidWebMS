import SweetAlert, { SweetAlertOptions } from 'sweetalert2';

// Questionned Swal Obj
export const QSwal = SweetAlert.mixin({
    heightAuto: false,
    confirmButtonText: "Yes, confirm",
    cancelButtonText: "No, cancel",
    customClass: {
        cancelButton: "btn btn-danger",
        confirmButton: "btn btn-primary"
    },
    showCancelButton: true
});

// Messaged Swal Obj
export const MSwal = SweetAlert.mixin({
    heightAuto: false,
    confirmButtonText: "Ok",
    showCancelButton: false,
    customClass: {
        cancelButton: "btn btn-info",
        confirmButton: "btn btn-primary"
    }
});
