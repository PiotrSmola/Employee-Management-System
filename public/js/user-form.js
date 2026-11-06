// The department field of the user form only applies to managers.
document.addEventListener('DOMContentLoaded', function() {
    const role = document.getElementById('role');
    const departmentField = document.getElementById('departmentField');
    const toggle = () => departmentField.classList.toggle('d-none', role.value !== 'manager');
    role.addEventListener('change', toggle);
    toggle();
});
