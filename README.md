## Getting Started
Follow the steps below to set up and run the David Inventory System on your server:

1. **Prerequisites**: Ensure that you have PHP, Laravel, and a database system (e.g., MySQL) installed on your server.

2. **Clone the Repository**: Clone this repository to your server or local development environment.

   https://github.com/saitama45/david.git

4. **Configuration**: Configure your database connection settings in the .env file.

5. **Install Dependencies**: Install the required PHP dependencies using Composer.

6. **Database Migrations**: Run the database migrations to create the necessary tables.

   php artisan migrate
  
7. **Seed Data (Optional)**: If you'd like to populate the system with sample data, run the seeders.

   php artisan db:seed
  
9. **Start the Application**: Start the Laravel development server.

   npm run dev
   
   php artisan serve
 
11. **Access the Application**: Open your web browser and access the application at http://localhost:8000 (or the URL provided by the Laravel server).
