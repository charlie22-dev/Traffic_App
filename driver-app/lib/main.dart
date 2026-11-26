import 'package:flutter/material.dart';

void main() {
  runApp(const MainApp());
}

class MainApp extends StatelessWidget {
  const MainApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CommuteLive Driver',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff123c35)),
        useMaterial3: true,
      ),
      home: Scaffold(
        appBar: AppBar(title: const Text('CommuteLive Driver')),
        body: const SafeArea(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Tracking OFF', style: TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
                SizedBox(height: 16),
                Text('Phase 0: driver app setup.'),
                SizedBox(height: 8),
                Text('No location is collected. Route selection and consent-based tracking will be added in a later phase.'),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
