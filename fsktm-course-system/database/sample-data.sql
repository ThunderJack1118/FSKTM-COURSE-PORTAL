-- FSKTM Course Management System Sample Data
-- Version: 1.0
-- Created: 2024-01-01

USE fsktm_courses;

-- Insert course categories
INSERT INTO course_categories (category_name, description, color_code) VALUES
('Programming', 'Programming and Software Development courses', '#28a745'),
('Database', 'Database Management and Design courses', '#dc3545'),
('Networking', 'Computer Networks and Security courses', '#ffc107'),
('AI & Machine Learning', 'Artificial Intelligence and ML courses', '#6f42c1'),
('Web Development', 'Frontend and Backend Web Development', '#fd7e14'),
('Mobile Development', 'Mobile App Development courses', '#20c997'),
('Data Science', 'Data Analysis and Visualization', '#17a2b8'),
('Cybersecurity', 'Information Security courses', '#343a40');

-- Insert admin user (password: Admin@123)
INSERT INTO users (username, email, password, first_name, last_name, user_type, staff_id, phone, department) VALUES
('admin', 'admin@fsktm.edu.my', '$2a$12$79SdsP1nbxQ5gbS2eptNW.Mb708OtSdiII3K9Vi2cLWdZHbD0tD0u', 'System', 'Administrator', 'admin', 'ADM001', '03-89212345', 'Administration');

-- Insert lecturers (password: Lecturer@123)
INSERT INTO users (username, email, password, first_name, last_name, user_type, staff_id, phone, department) VALUES
('dr_ahmad', 'ahmad@fsktm.edu.my', '$2a$12$OH8SHQ6HktwoEyrLrv51iuHETcQg2zEytgdtBN7EgeLMcDSZr4HDy', 'Ahmad', 'Hassan', 'lecturer', 'LEC001', '03-89210001', 'Computer Science'),
('dr_siti', 'siti@fsktm.edu.my', '$2a$12$OH8SHQ6HktwoEyrLrv51iuHETcQg2zEytgdtBN7EgeLMcDSZr4HDy', 'Siti', 'Nurhaliza', 'lecturer', 'LEC002', '03-89210002', 'Information Technology'),
('prof_lee', 'lee@fsktm.edu.my', '$$2a$12$OH8SHQ6HktwoEyrLrv51iuHETcQg2zEytgdtBN7EgeLMcDSZr4HDy', 'Lee', 'Wei Ming', 'lecturer', 'LEC003', '03-89210003', 'Software Engineering'),
('dr_rajesh', 'rajesh@fsktm.edu.my', '$2a$12$OH8SHQ6HktwoEyrLrv51iuHETcQg2zEytgdtBN7EgeLMcDSZr4HDy', 'Rajesh', 'Kumar', 'lecturer', 'LEC004', '03-89210004', 'Artificial Intelligence'),
('dr_fatimah', 'fatimah@fsktm.edu.my', '$2a$12$OH8SHQ6HktwoEyrLrv51iuHETcQg2zEytgdtBN7EgeLMcDSZr4HDy', 'Fatimah', 'Ali', 'lecturer', 'LEC005', '03-89210005', 'Network Security');

-- Insert students (password: Student@123)
INSERT INTO users (username, email, password, first_name, last_name, user_type, student_id, phone, date_of_birth, department) VALUES
('john_doe', 'john@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'John', 'Doe', 'student', 'STU001', '019-1234567', '2001-05-15', 'Computer Science'),
('jane_smith', 'jane@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Jane', 'Smith', 'student', 'STU002', '019-2345678', '2000-12-03', 'Information Technology'),
('ahmad_ibrahim', 'ahmad@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Ahmad', 'Ibrahim', 'student', 'STU003', '019-3456789', '2001-08-22', 'Software Engineering'),
('mei_ling', 'mei@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Mei', 'Ling', 'student', 'STU004', '019-4567890', '2002-03-10', 'Artificial Intelligence'),
('ali_omar', 'ali@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Ali', 'Omar', 'student', 'STU005', '019-5678901', '2001-11-25', 'Network Security'),
('sara_wong', 'sara@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Sara', 'Wong', 'student', 'STU006', '019-6789012', '2000-07-18', 'Computer Science'),
('david_lim', 'david@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'David', 'Lim', 'student', 'STU007', '019-7890123', '2001-09-30', 'Information Technology'),
('nurul_huda', 'nurul@student.fsktm.edu.my', '$2a$12$lB9OHQh8th5S/d.nvM.0GuIZwuKgXDywBZ8KH4PPA1f/zwfTJG7ES', 'Nurul', 'Huda', 'student', 'STU008', '019-8901234', '2002-01-05', 'Software Engineering');

-- Insert courses
INSERT INTO courses (course_code, course_name, description, credits, semester, academic_year, max_students, instructor_id, schedule_day, schedule_time, classroom, category_id, prerequisites) VALUES
('CSC101', 'Introduction to Programming', 'Fundamentals of programming using Python', 3, '1', '2024/2025', 40, 2, 'Monday', '08:00-10:00', 'Lab A1', 1, NULL),
('CSC102', 'Database Systems', 'Introduction to relational databases and SQL', 3, '1', '2024/2025', 35, 3, 'Tuesday', '10:00-12:00', 'Lab B2', 2, NULL),
('CSC201', 'Web Development', 'Full-stack web development with HTML, CSS, JavaScript, and PHP', 3, '1', '2024/2025', 30, 3, 'Wednesday', '14:00-16:00', 'Lab C3', 5, 'CSC101'),
('CSC202', 'Object-Oriented Programming', 'Advanced programming concepts using Java', 3, '2', '2024/2025', 35, 2, 'Thursday', '09:00-11:00', 'Lab A2', 1, 'CSC101'),
('CSC301', 'Mobile App Development', 'Cross-platform mobile development with Flutter', 3, '2', '2024/2025', 25, 4, 'Friday', '11:00-13:00', 'Lab D1', 6, 'CSC201'),
('CSC302', 'Artificial Intelligence', 'Introduction to AI and machine learning', 3, '1', '2024/2025', 30, 5, 'Monday', '14:00-16:00', 'Lecture Hall 1', 4, 'CSC101'),
('CSC401', 'Computer Networks', 'Network protocols and security', 3, '2', '2024/2025', 30, 6, 'Tuesday', '15:00-17:00', 'Lab E1', 3, NULL),
('CSC402', 'Data Science Fundamentals', 'Data analysis and visualization with Python', 3, '1', '2024/2025', 25, 5, 'Wednesday', '10:00-12:00', 'Lab F1', 7, 'CSC101'),
('CSC501', 'Cybersecurity Essentials', 'Information security principles and practices', 3, '2', '2024/2025', 25, 6, 'Thursday', '13:00-15:00', 'Lecture Hall 2', 8, 'CSC401'),
('CSC502', 'Advanced Web Development', 'Modern web frameworks and APIs', 3, '1', '2024/2025', 20, 3, 'Friday', '09:00-11:00', 'Lab C2', 5, 'CSC201');

-- Insert registrations
INSERT INTO registrations (user_id, course_id, status, registration_date, grade, attendance_percentage, final_marks) VALUES
-- Student 1 (John Doe)
(7, 1, 'approved', '2024-01-10 09:00:00', 'A', 95.50, 92.00),
(7, 2, 'approved', '2024-01-10 09:05:00', 'A-', 90.25, 87.50),
(7, 3, 'approved', '2024-01-11 10:00:00', 'B+', 88.75, 82.00),

-- Student 2 (Jane Smith)
(8, 1, 'approved', '2024-01-10 09:30:00', 'B+', 85.00, 80.50),
(8, 4, 'approved', '2024-01-12 11:00:00', 'A', 93.25, 91.00),
(8, 6, 'approved', '2024-01-12 11:05:00', 'A-', 91.50, 88.75),

-- Student 3 (Ahmad Ibrahim)
(9, 2, 'approved', '2024-01-11 09:00:00', 'A', 96.00, 93.50),
(9, 3, 'approved', '2024-01-11 09:30:00', 'B', 82.50, 77.00),
(9, 5, 'pending', '2024-01-15 14:00:00', NULL, 0.00, 0.00),

-- Student 4 (Mei Ling)
(10, 1, 'approved', '2024-01-13 10:00:00', 'A+', 98.00, 95.00),
(10, 6, 'approved', '2024-01-13 10:05:00', 'A', 94.50, 92.25),
(10, 8, 'approved', '2024-01-14 11:00:00', 'B+', 87.75, 83.50),

-- Student 5 (Ali Omar)
(11, 4, 'approved', '2024-01-14 09:00:00', 'A-', 90.00, 86.50),
(11, 7, 'approved', '2024-01-14 09:30:00', 'B', 83.25, 78.00),
(11, 9, 'approved', '2024-01-15 10:00:00', 'B+', 86.50, 81.75),

-- Student 6 (Sara Wong)
(12, 3, 'approved', '2024-01-15 11:00:00', 'A', 94.00, 90.50),
(12, 5, 'approved', '2024-01-15 11:30:00', 'A-', 89.75, 85.25),
(12, 10, 'pending', '2024-01-16 09:00:00', NULL, 0.00, 0.00);

-- Update current_enrolled count for courses
UPDATE courses c SET c.current_enrolled = (
    SELECT COUNT(*) FROM registrations r 
    WHERE r.course_id = c.course_id AND r.status = 'approved'
);

-- Insert announcements
INSERT INTO announcements (title, content, course_id, created_by, priority) VALUES
('Welcome to New Academic Year', 'Dear students, welcome to the 2024/2025 academic year! Important dates will be shared soon.', NULL, 1, 'high'),
('Database Systems Lab Change', 'The lab for CSC102 Database Systems has been changed to Lab B3 starting next week.', 2, 3, 'medium'),
('Programming Assignment Deadline', 'Reminder: Assignment 1 for CSC101 is due this Friday at 5pm. Late submissions will be penalized.', 1, 2, 'high'),
('Career Fair Announcement', 'The annual FSKTM Career Fair will be held on March 15th. Register at the student portal.', NULL, 1, 'medium'),
('Mobile Development Prerequisites', 'Students registering for CSC301 must complete CSC201 first. Contact department if you have questions.', 5, 4, 'medium');

-- Insert assignments
INSERT INTO assignments (course_id, title, description, due_date, max_marks) VALUES
(1, 'Python Basics', 'Complete the Python exercises on variables, loops, and functions', '2024-02-15 23:59:00', 100),
(1, 'OOP Project', 'Create a Python program demonstrating OOP concepts', '2024-03-20 23:59:00', 100),
(2, 'Database Design', 'Design an ER diagram for a library management system', '2024-02-20 23:59:00', 100),
(3, 'Website Prototype', 'Create a responsive website with HTML/CSS', '2024-02-25 23:59:00', 100),
(6, 'AI Research Paper', 'Write a 5-page paper on an AI application', '2024-03-10 23:59:00', 100);

-- Insert submissions
INSERT INTO submissions (assignment_id, user_id, file_path, marks, feedback, status) VALUES
(1, 7, 'submissions/python_basics_john.zip', 85.5, 'Good work, but need more comments in code', 'graded'),
(1, 8, 'submissions/python_basics_jane.zip', 92.0, 'Excellent implementation!', 'graded'),
(1, 10, 'submissions/python_basics_mei.zip', 95.0, 'Perfect solution with great documentation', 'graded'),
(3, 7, 'submissions/db_design_john.pdf', 78.0, 'Good start but missing some relationships', 'graded'),
(3, 9, 'submissions/db_design_ahmad.pdf', 88.5, 'Well-designed with clear documentation', 'graded'),
(4, 12, 'submissions/web_prototype_sara.zip', 91.0, 'Beautiful design and fully responsive', 'graded');