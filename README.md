# Laravel-Interview-Task-Flash-Sale-Checkout-API
This is an API which introduces a couple of functionalities such as: Quering a product, holding a certain amount of product, doing an order, and so on.

A better way of showing what it does as well is to show it's endpoints which we as interact with as both users and developers:
- GET: /api/products/{product_id} : This returns the details of a specific product
- POST: /api/holds : This holds a certain amount of product for a period of 2 minutes. If two minutes are passed without a successfuly payment operation, the hold gets expired and removed automatically and the held amounts of the product gets returned to the stock for other users.
- POST: /api/orders: Issues an order using a valid hold_id
- POST: /api/payments/webhook: Takes the order_id, idempotency_id, and payment process result from a supossed payment service which is used to verify that an order has been issued successfuly.

This application was built using tools and technologies which includes the following:
- PHP
- Laravel
- PhpUnit (for testing)
- Redis (for caching)
- MySQL (for persistent data storage)
- Laravel Scheduler: Used to periodically process holds that have expired. It runs the background checking periodically if any hold in Redis has expired.

Since the api works in heavy-load context, several techniques were put in place to ensure data correctiveness, high-performance. These techinques included the following:
- Redis for caching holds values instead of loading them from the database especially since hold values last for 2 minutes
- Indexing: Used indecies on columns that are queries a lot to speed up the query performance
- Transactions and locking: Used mulithreading tools such as locking and transactions to ensure that concurrent requests don't corrupt the data or lead to incorrect results or behaviour like overselling
- Avoiding N+1 problem: This was avoided by using Redis batching which allowed a group of queries to done sent in one go instead of sending each one indivisually to redis.

  In order to run the app, you must have Laravel and Composer installed on your computer. I will assume that it's installed.
  1- Go to to the project folder in CMD
  2- Type: composer install
  it will download and install all of the project dependancies
  3- Create a database in MySQL called "sales"
  4- Download Redis and then run it. It needs to be running for the application to function.
  5- Go back to CMD and type: php artisan migrate --seed
  This will run the migrations and seeding which will create data schemas along with their inital values
  6- At this point you can run the application and try testing it (running its tests). You can do that by writing this in the CMD prompt:
  For running the application -> php artisan serve
  For running the application tests -> php artisan test
  7- If you want to run the application scheduler which checks for holds that expired periodically, you will find a bash file called "scheduler.bash". Open that file using Git Bash on Windows or using Linux. It should look like this:
  
<img width="745" height="449" alt="2025-12-02_211922" src="https://github.com/user-attachments/assets/961fa243-3ec4-40cb-843c-6e1e72fddaf6" />

  NOTE: This application assumes that the username of MySQL is root and the password is admin123. If that's not the case, you can change it in the ".env" file.

  Finally, if you want to know where the application logs are, you will find them in the /storage/logs folder. You will see a file called "laravel.log" that's the log file.
  <img width="1920" height="1030" alt="2025-12-02_211649" src="https://github.com/user-attachments/assets/0cfbd82a-04e7-4e0b-8477-a74c1cda6038" />
