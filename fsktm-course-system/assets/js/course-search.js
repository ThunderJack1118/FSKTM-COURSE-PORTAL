document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.course-search input[name="search"]');
    const courseItems = document.querySelectorAll('.course-item');
    
    if (searchInput && courseItems.length > 0) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            courseItems.forEach(item => {
                const courseName = item.querySelector('.course-info h3').textContent.toLowerCase();
                const courseCode = item.querySelector('.course-info .course-code').textContent.toLowerCase();
                const instructor = item.querySelector('.course-info .instructor').textContent.toLowerCase();
                const description = item.querySelector('.course-info .description').textContent.toLowerCase();
                
                if (courseName.includes(searchTerm) || 
                    courseCode.includes(searchTerm) || 
                    instructor.includes(searchTerm) || 
                    description.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
});