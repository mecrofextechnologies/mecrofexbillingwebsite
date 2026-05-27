<!-- Loading Overlay -->
<div id="preloader" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-xl px-8 py-6 flex items-center space-x-3">
        <div class="h-5 w-5 border-2 border-green-500 border-t-transparent rounded-full animate-spin"></div>
        <span class="text-gray-700 font-medium">Loading...Please wait!</span>
    </div>
</div>

<script>
window.addEventListener("load", function () {
    const loader = document.getElementById("preloader");
    loader.style.opacity = "0";
    loader.style.transition = "opacity 0.3s ease";

    setTimeout(() => {
        loader.style.display = "none";
    }, 300);
});
</script>